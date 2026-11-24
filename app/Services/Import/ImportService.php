<?php

namespace App\Services\Import;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportError;
use App\Models\Machine;
use App\Models\Material;
use App\Models\User;
use App\Services\SummaryBuilder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportService
{
    public function __construct(private SummaryBuilder $summaries) {}

    /**
     * Tahap 1: baca file, validasi, simpan hasil parsing, hitung dampak (data baru / diganti).
     */
    public function preview(ImportBatch $batch): ImportBatch
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $path = storage_path('app/private/'.$batch->file_path);
        $parser = new WorkbookParser;
        $type = $parser->detectType($path);

        if ($type === null) {
            $batch->update(['status' => 'failed', 'message' => 'Format file tidak dikenali. Gunakan export laporan harian (Sheet hasil + DT) atau export SAP (Order | GI | GR).']);

            return $batch;
        }

        if ($type === 'daily') {
            $knownMachines = Machine::pluck('id', 'code')->all();
            $rows = $parser->parseDaily($path, $knownMachines);
            $groups = $this->groupSummary($rows);
        } else {
            $rows = $parser->parseSap($path);
            $existing = DB::table('sap_order_results')->whereIn('order_no', array_column($rows, 'order'))->count();
            $matched = DB::table('production_orders')->whereIn('order_no', array_column($rows, 'order'))->count();
            $groups = ['sap' => ['rows' => count($rows), 'existing' => $existing, 'matched' => $matched]];
        }

        $this->storeIssues($batch, $parser->issues);
        @mkdir(dirname($batch->parsedPath()), 0775, true);
        file_put_contents($batch->parsedPath(), json_encode($rows));

        $errors = collect($parser->issues)->where('level', 'error')->count();
        $batch->update([
            'type' => $type,
            'status' => 'preview',
            'rows_total' => count($rows) + $errors,
            'rows_valid' => count($rows),
            'rows_error' => $errors,
            'groups' => $groups,
            'message' => count($rows) === 0 ? 'Tidak ada baris valid untuk diimport.' : null,
        ]);

        return $batch;
    }

    /**
     * Tahap 2: simpan. Untuk setiap tanggal+shift di file, data lama dihapus lalu diganti
     * (tidak pernah dobel). Data lebih lama dari lock_days hanya boleh diganti Admin Master.
     */
    public function commit(ImportBatch $batch, ?User $user, bool $force = false): ImportBatch
    {
        // Satu proses simpan / hapus dalam satu waktu: mencegah dobel jika 2 admin (atau klik ganda) menyimpan bersamaan.
        return $this->exclusive(fn () => $this->commitLocked($batch->fresh(), $user, $force));
    }

    private function exclusive(\Closure $fn): mixed
    {
        try {
            return Cache::lock('production-import-write', 900)->block(120, $fn);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Ada import lain yang sedang disimpan. Tunggu sebentar lalu coba lagi.');
        }
    }

    private function commitLocked(ImportBatch $batch, ?User $user, bool $force): ImportBatch
    {
        if ($batch->status !== 'preview') {
            throw new RuntimeException('Batch ini sudah diproses.');
        }
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $rows = json_decode((string) @file_get_contents($batch->parsedPath()), true) ?? [];
        if (! $rows) {
            throw new RuntimeException('Tidak ada data valid untuk disimpan.');
        }

        if ($batch->type === 'sap') {
            return $this->commitSap($batch, $rows, $user);
        }

        // Data di database berubah sejak preview (import / hapus lain)? -> preview sudah tidak akurat.
        $stale = $this->staleGroups($batch->groups ?? []);
        if ($stale) {
            throw new RuntimeException('Data '.implode(', ', $stale).' sudah berubah sejak preview ini dibuat (ada import / hapus lain). '
                .'Upload ulang file supaya perbandingan yang ditampilkan sesuai data terbaru.');
        }

        if (! $force) {
            $locked = $this->lockedGroups($batch->groups ?? [], $user);
            if ($locked) {
                throw new RuntimeException('Data berikut lebih lama dari '.config('production.lock_days').
                    ' hari dan sudah ada, hanya Admin Master yang boleh menggantinya: '.implode(', ', $locked));
            }
        }

        $replaced = 0;
        $now = now();
        DB::transaction(function () use ($batch, $rows, $user, $now, &$replaced) {
            $groupPairs = collect($rows)->map(fn ($r) => [$r['date'], $r['shift']])->unique(fn ($p) => $p[0].'|'.$p[1])->values();

            // Data lama tanggal+shift yang sama di-soft-delete (tetap bisa ditelusuri), lalu diganti.
            // Waktu & orang yang PERTAMA kali menginput dibawa ke data baru.
            $origin = [];
            $oldOrderIds = []; // order dari data lama: harus dihitung ulang juga (bisa jadi hilang dari file baru)
            foreach ($groupPairs as [$date, $shift]) {
                $old = DB::table('production_reports')->where('production_date', $date)->where('shift', $shift)->whereNull('deleted_at');
                $first = (clone $old)->orderBy('created_at')->first(['created_at', 'created_by']);
                if ($first) {
                    $origin[$date.'|'.$shift] = [$first->created_at, $first->created_by];
                }
                array_push($oldOrderIds, ...(clone $old)->whereNotNull('production_order_id')->distinct()->pluck('production_order_id')->all());
                $replaced += (clone $old)->update(['deleted_at' => $now, 'deleted_by' => $user?->id]);
            }

            $lookups = $this->buildLookups($rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                $insert = [];
                foreach ($chunk as $r) {
                    $machine = $lookups['machines'][$r['machine']];
                    $dtMin = array_sum(array_column($r['downtimes'], 0));
                    $setup = 0;
                    foreach ($r['downtimes'] as $key => [$min]) {
                        if (($lookups['reasonCategory'][$key] ?? null) === 'setup') {
                            $setup += $min;
                        }
                    }
                    [$ncCause, $ncrNo] = $this->splitNc($r['nc_note'], $r['nc']);

                    $insert[] = [
                        'production_date' => $r['date'],
                        'shift' => $r['shift'],
                        'machine_id' => $machine['id'],
                        'area_id' => $machine['area_id'],
                        'building' => $machine['building'],
                        'production_order_id' => $lookups['orders'][$r['order']] ?? null,
                        'order_no' => $r['order'],
                        'customer_id' => $lookups['customers'][Customer::normalizeName($r['customer'])] ?? null,
                        'material_id' => $r['material'] ? ($lookups['materials'][$r['material']] ?? null) : null,
                        'start_time' => $r['start'],
                        'end_time' => $r['end'],
                        'raw_length' => $r['raw_length'] ?: null,
                        'raw_qty' => max(0, $r['raw_qty']),
                        'result_length' => $r['result_length'] ?: null,
                        'ok_qty' => max(0, $r['ok']),
                        'nc_qty' => max(0, $r['nc']),
                        'nc_note' => $r['nc_note'] ? mb_substr($r['nc_note'], 0, 250) : null,
                        'nc_cause' => $ncCause,
                        'ncr_no' => $ncrNo,
                        'reject_qty' => max(0, $r['reject']),
                        'reject_note' => $r['reject_note'] ? mb_substr($r['reject_note'], 0, 250) : null,
                        'total_cutting_qty' => max(0, $r['total']),
                        'working_minutes' => max(0, $r['working']),
                        'downtime_minutes' => $dtMin,
                        'setup_minutes' => $setup,
                        'cutting_minutes' => max(0, $r['working'] - $dtMin),
                        'ok_kg' => $r['ok_kg'],
                        'nc_kg' => $r['nc_kg'],
                        'reject_kg' => $r['reject_kg'],
                        'total_kg' => $r['total_kg'],
                        'downtime_note' => $r['downtime_note'],
                        'has_downtime_sheet' => $r['has_dt'],
                        'import_batch_id' => $batch->id,
                        'row_no' => $r['row'],
                        'created_at' => $origin[$r['date'].'|'.$r['shift']][0] ?? $now,
                        'created_by' => $origin[$r['date'].'|'.$r['shift']][1] ?? $user?->id,
                        'updated_at' => $now,
                        'updated_by' => $user?->id,
                    ];
                }
                DB::table('production_reports')->insert($insert);

                $ids = DB::table('production_reports')->where('import_batch_id', $batch->id)
                    ->whereIn('row_no', array_column($chunk, 'row'))->pluck('id', 'row_no');

                $defects = [];
                $downs = [];
                foreach ($chunk as $r) {
                    $rid = $ids[$r['row']];
                    foreach ($r['defects'] as $key => $qty) {
                        $defects[] = ['production_report_id' => $rid, 'defect_type_id' => $lookups['defects'][$key], 'qty' => $qty];
                    }
                    foreach ($r['downtimes'] as $key => [$min, $freq]) {
                        $downs[] = ['production_report_id' => $rid, 'downtime_reason_id' => $lookups['reasons'][$key], 'minutes' => $min, 'frequency' => $freq];
                    }
                }
                foreach (array_chunk($defects, 1000) as $c) {
                    DB::table('production_defects')->insert($c);
                }
                foreach (array_chunk($downs, 1000) as $c) {
                    DB::table('production_downtimes')->insert($c);
                }
            }

            $this->summaries->refreshOrders(array_merge(array_values($lookups['orders']), $oldOrderIds));
        });

        $this->summaries->rebuildDates(array_unique(array_column($rows, 'date')));

        $batch->update([
            'status' => 'done',
            'rows_imported' => count($rows),
            'rows_replaced' => $replaced,
            'user_id' => $batch->user_id ?? $user?->id,
            'committed_at' => now(),
        ]);
        @unlink($batch->parsedPath());

        // audit trail: satu catatan per tanggal+shift
        foreach ($batch->groups ?? [] as $g) {
            $diff = $g['diff'] ?? null;
            ActivityLog::record($g['existing'] ? 'update' : 'create', [
                'production_data', $batch->id,
                'Data produksi '.Carbon::parse($g['date'])->format('d/m/Y').' · Shift '.$g['shift'],
                $g['date'].'|'.$g['shift'],
            ], ActivityLog::summaryPairs([
                'File' => $batch->file_name,
                'Jumlah baris' => $g['rows'],
                'Baris lama (diganti)' => $g['existing'] ?: null,
                'Baris sama' => $diff['same'] ?? null,
                'Baris berubah' => $diff['changed_count'] ?? null,
                'Baris tambahan' => $diff['added_count'] ?? null,
                'Baris hilang' => $diff['removed'] ?? null,
            ]), $user);
        }

        return $batch;
    }

    private function commitSap(ImportBatch $batch, array $rows, ?User $user = null): ImportBatch
    {
        $now = now();
        $existing = 0;
        DB::transaction(function () use ($batch, $rows, $now, &$existing) {
            $existing = DB::table('sap_order_results')->whereIn('order_no', array_column($rows, 'order'))->count();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('sap_order_results')->upsert(array_map(fn ($r) => [
                    'order_no' => $r['order'],
                    'gi_kg' => $r['gi_kg'],
                    'gr_percent' => $r['gr_percent'],
                    'gr_kg' => $r['gi_kg'] !== null && $r['gr_percent'] !== null ? round($r['gi_kg'] * $r['gr_percent'] / 100, 3) : null,
                    'import_batch_id' => $batch->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk), ['order_no'], ['gi_kg', 'gr_percent', 'gr_kg', 'import_batch_id', 'updated_at']);
            }
        });
        SummaryBuilder::bumpCache();
        $batch->update(['status' => 'done', 'rows_imported' => count($rows), 'rows_replaced' => $existing, 'committed_at' => now()]);
        @unlink($batch->parsedPath());
        ActivityLog::record($existing ? 'update' : 'create', ['sap_data', $batch->id, 'Data SAP: '.$batch->file_name, null],
            ActivityLog::summaryPairs(['File' => $batch->file_name, 'Order' => count($rows), 'Order diperbarui' => $existing]), $user);

        return $batch;
    }

    /** Ringkasan per tanggal+shift: jumlah baris baru, data lama yang akan diganti. */
    private function groupSummary(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $k = $r['date'].'|'.$r['shift'];
            $groups[$k] ??= ['date' => $r['date'], 'shift' => $r['shift'], 'rows' => 0, 'machines' => [], 'ok' => 0, 'existing' => 0, 'existing_by' => null, 'existing_at' => null];
            $groups[$k]['rows']++;
            $groups[$k]['ok'] += $r['ok'];
            $groups[$k]['machines'][$r['machine']] = true;
        }
        if (! $groups) {
            return [];
        }

        $dates = array_unique(array_column($groups, 'date'));
        $existing = DB::table('production_reports as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.updated_by')
            ->whereIn('r.production_date', $dates)->whereNull('r.deleted_at')
            ->groupBy('r.production_date', 'r.shift')
            ->selectRaw('r.production_date d, r.shift s, COUNT(*) c, MAX(u.name) who, MAX(r.updated_at) at')
            ->get();
        foreach ($existing as $e) {
            $k = Carbon::parse($e->d)->toDateString().'|'.$e->s;
            if (isset($groups[$k])) {
                $groups[$k]['existing'] = $e->c;
                $groups[$k]['existing_by'] = $e->who;
                $groups[$k]['existing_at'] = $e->at;
            }
        }
        // bandingkan isi lama vs baru untuk tanggal+shift yang akan diganti
        $byGroup = [];
        foreach ($rows as $r) {
            $byGroup[$r['date'].'|'.$r['shift']][] = $r;
        }
        foreach ($groups as $k => &$g) {
            $g['machines'] = count($g['machines']);
            if ($g['existing']) {
                $g['diff'] = $this->diffGroup($g['date'], (int) $g['shift'], $byGroup[$k] ?? []);
            }
        }
        unset($g);
        ksort($groups);

        return array_values($groups);
    }

    /** Field pembanding satu baris (dinormalisasi supaya data lama & baru bisa dibandingkan). */
    private const DIFF_FIELDS = ['Mesin', 'Order', 'Mulai', 'Selesai', 'Pjg bahan', 'Jml bahan', 'Pjg hasil', 'Baik', 'NC', 'Reject', 'Jam kerja', 'Downtime'];

    private static function diffNum($v): string
    {
        return rtrim(rtrim(number_format((float) ($v ?? 0), 2, '.', ''), '0'), '.');
    }

    /**
     * Cocokkan baris baru dengan baris lama: yang identik = sama; sisanya dipasangkan
     * lewat mesin+order (= berubah, beserta kolom yang berubah); sisanya = tambahan / hilang.
     */
    private function diffGroup(string $date, int $shift, array $newRows): array
    {
        $old = DB::table('production_reports as r')->join('machines as m', 'm.id', '=', 'r.machine_id')
            ->where('r.production_date', $date)->where('r.shift', $shift)->whereNull('r.deleted_at')
            ->orderBy('r.id')
            ->get(['m.code', 'r.order_no', 'r.start_time', 'r.end_time', 'r.raw_length', 'r.raw_qty', 'r.result_length',
                'r.ok_qty', 'r.nc_qty', 'r.reject_qty', 'r.working_minutes', 'r.downtime_minutes'])
            ->map(fn ($o) => array_combine(self::DIFF_FIELDS, [
                $o->code, (string) $o->order_no, substr((string) $o->start_time, 0, 5), substr((string) $o->end_time, 0, 5),
                self::diffNum($o->raw_length), (string) (int) $o->raw_qty, self::diffNum($o->result_length),
                (string) (int) $o->ok_qty, (string) (int) $o->nc_qty, (string) (int) $o->reject_qty,
                (string) (int) $o->working_minutes, (string) (int) $o->downtime_minutes,
            ]))->all();

        $new = array_map(fn ($r) => ['row' => $r['row'], 'f' => array_combine(self::DIFF_FIELDS, [
            $r['machine'], (string) $r['order'], substr((string) $r['start'], 0, 5), substr((string) $r['end'], 0, 5),
            self::diffNum($r['raw_length']), (string) max(0, (int) $r['raw_qty']), self::diffNum($r['result_length']),
            (string) max(0, (int) $r['ok']), (string) max(0, (int) $r['nc']), (string) max(0, (int) $r['reject']),
            (string) max(0, (int) $r['working']), (string) (int) array_sum(array_column($r['downtimes'], 0)),
        ])], $newRows);

        $pool = [];
        foreach ($old as $i => $o) {
            $pool[implode('|', $o)][] = $i;
        }
        $same = 0;
        $unmatched = [];
        foreach ($new as $n) {
            $sig = implode('|', $n['f']);
            if (! empty($pool[$sig])) {
                unset($old[array_shift($pool[$sig])]);
                $same++;
            } else {
                $unmatched[] = $n;
            }
        }

        $changed = [];
        $added = [];
        foreach ($unmatched as $n) {
            $match = null;
            foreach ($old as $i => $o) {
                if ($o['Mesin'] === $n['f']['Mesin'] && $o['Order'] === $n['f']['Order']) {
                    $match = $i;
                    if ($o['Mulai'] === $n['f']['Mulai']) {
                        break;
                    }
                }
            }
            if ($match === null) {
                $added[] = ['row' => $n['row'], 'machine' => $n['f']['Mesin'], 'order' => $n['f']['Order']];

                continue;
            }
            $fields = [];
            foreach (self::DIFF_FIELDS as $f) {
                if ($old[$match][$f] !== $n['f'][$f]) {
                    $fields[] = [$f, $old[$match][$f], $n['f'][$f]];
                }
            }
            $changed[] = ['row' => $n['row'], 'machine' => $n['f']['Mesin'], 'order' => $n['f']['Order'], 'fields' => $fields];
            unset($old[$match]);
        }

        return [
            'same' => $same,
            'changed' => array_slice($changed, 0, 60),
            'changed_count' => count($changed),
            'added' => array_slice($added, 0, 60),
            'added_count' => count($added),
            'removed' => count($old),
            'removed_rows' => array_slice(array_values(array_map(fn ($o) => ['machine' => $o['Mesin'], 'order' => $o['Order'], 'start' => $o['Mulai']], $old)), 0, 60),
        ];
    }

    /** Tanggal+shift yang isinya di database tidak lagi sama dengan saat preview. */
    private function staleGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $now = DB::table('production_reports')->where('production_date', $g['date'])->where('shift', $g['shift'])
                ->whereNull('deleted_at')->selectRaw('COUNT(*) c, MAX(updated_at) at')->first();
            $sameCount = (int) $now->c === (int) ($g['existing'] ?? 0);
            $sameTime = (string) ($now->at ?? '') === (string) ($g['existing_at'] ?? '');
            if (! $sameCount || ! $sameTime) {
                $out[] = Carbon::parse($g['date'])->format('d/m/Y').' S'.$g['shift'];
            }
        }

        return $out;
    }

    /**
     * Hapus (soft delete) seluruh data satu tanggal+shift. Data tetap tersimpan untuk penelusuran,
     * ringkasan dashboard & akumulasi order dihitung ulang, aksi dicatat di log.
     */
    public function deleteGroup(string $date, int $shift, User $user, string $reason): int
    {
        return $this->exclusive(function () use ($date, $shift, $user, $reason) {
            $q = DB::table('production_reports')->where('production_date', $date)->where('shift', $shift)->whereNull('deleted_at');
            $count = (clone $q)->count();
            if (! $count) {
                throw new RuntimeException('Tidak ada data untuk tanggal & shift tersebut.');
            }
            if ($this->lockedGroups([['date' => $date, 'shift' => $shift, 'existing' => $count]], $user)) {
                throw new RuntimeException('Data lebih lama dari '.config('production.lock_days').' hari hanya bisa dihapus Admin Master.');
            }
            $ok = (int) (clone $q)->sum('ok_qty');
            $orderIds = (clone $q)->whereNotNull('production_order_id')->distinct()->pluck('production_order_id')->all();

            DB::transaction(function () use ($q, $user, $orderIds) {
                (clone $q)->update(['deleted_at' => now(), 'deleted_by' => $user->id]);
                $this->summaries->refreshOrders($orderIds);
            });
            $this->summaries->rebuildDates([$date]);

            ActivityLog::record('delete', [
                'production_data', null, 'Data produksi '.Carbon::parse($date)->format('d/m/Y').' · Shift '.$shift, $date.'|'.$shift,
            ], ActivityLog::summaryPairs(['Alasan' => $reason, 'Jumlah baris' => $count, 'Hasil baik' => $ok]), $user);

            return $count;
        });
    }

    public function lockedGroups(array $groups, ?User $user): array
    {
        if ($user?->isMaster()) {
            return [];
        }
        $limit = now()->startOfDay()->subDays(config('production.lock_days'));

        return collect($groups)
            ->filter(fn ($g) => ($g['existing'] ?? 0) > 0 && Carbon::parse($g['date'])->lt($limit))
            ->map(fn ($g) => Carbon::parse($g['date'])->format('d/m/Y').' S'.$g['shift'])
            ->values()->all();
    }

    private function buildLookups(array $rows): array
    {
        $now = now();

        // customer
        $names = collect($rows)->map(fn ($r) => Customer::normalizeName($r['customer']))->filter()->unique()->values();
        $existingC = DB::table('customers')->whereIn('name', $names)->pluck('id', 'name')->all();
        $newC = $names->reject(fn ($n) => isset($existingC[$n]))->map(fn ($n) => ['name' => $n, 'created_at' => $now, 'updated_at' => $now])->values()->all();
        foreach (array_chunk($newC, 500) as $c) {
            DB::table('customers')->insert($c);
        }
        $customers = DB::table('customers')->whereIn('name', $names)->pluck('id', 'name')->all();

        // material
        $codes = collect($rows)->pluck('material')->filter()->unique()->values();
        $lengthByCode = collect($rows)->filter(fn ($r) => $r['material'] && $r['result_length'])->pluck('result_length', 'material')->all();
        $existingM = DB::table('materials')->whereIn('code', $codes)->pluck('id', 'code')->all();
        $newM = $codes->reject(fn ($c) => isset($existingM[$c]))->map(function ($c) use ($now, $lengthByCode) {
            $p = Material::parseCode($c);

            return ['code' => $c, 'od_mm' => $p['od_mm'] ?? null, 'thickness_mm' => $p['thickness_mm'] ?? null,
                'length_mm' => $p['length_mm'] ?? ($lengthByCode[$c] ?? null), 'created_at' => $now, 'updated_at' => $now];
        })->values()->all();
        foreach (array_chunk($newM, 500) as $c) {
            DB::table('materials')->insert($c);
        }
        $materials = DB::table('materials')->whereIn('code', $codes)->pluck('id', 'code')->all();

        // order: dibuat otomatis dari data (pertama kali muncul)
        $orderInfo = [];
        foreach ($rows as $r) {
            $orderInfo[$r['order']] ??= [
                'customer_id' => $customers[Customer::normalizeName($r['customer'])] ?? null,
                'material_id' => $r['material'] ? ($materials[$r['material']] ?? null) : null,
            ];
        }
        $orderNos = array_keys($orderInfo);
        foreach (array_chunk($orderNos, 1000) as $chunk) {
            DB::table('production_orders')->insertOrIgnore(array_map(fn ($o) => [
                'order_no' => $o, 'customer_id' => $orderInfo[$o]['customer_id'], 'material_id' => $orderInfo[$o]['material_id'],
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $orders = [];
        foreach (array_chunk($orderNos, 1000) as $chunk) {
            $orders += DB::table('production_orders')->whereIn('order_no', $chunk)->pluck('id', 'order_no')->all();
        }

        return [
            'machines' => Machine::get(['id', 'code', 'area_id', 'building'])->keyBy('code')->map->only(['id', 'area_id', 'building'])->all(),
            'customers' => $customers,
            'materials' => $materials,
            'orders' => $orders,
            'defects' => DB::table('defect_types')->pluck('id', 'key')->all(),
            'reasons' => DB::table('downtime_reasons')->pluck('id', 'key')->all(),
            'reasonCategory' => DB::table('downtime_reasons as r')->join('downtime_categories as c', 'c.id', '=', 'r.downtime_category_id')->pluck('c.key', 'r.key')->all(),
        ];
    }

    /** "KARAT NCR-202" -> ["KARAT", "NCR-202"] */
    private function splitNc(?string $note, int $nc): array
    {
        if ($nc <= 0) {
            return [null, null];
        }
        $note = strtoupper(trim((string) $note));
        $ncr = null;
        if (preg_match('/NCR[\s\-.]*(\d+)/', $note, $m)) {
            $ncr = 'NCR-'.$m[1];
        }
        $cause = trim(preg_replace('/NCR[\s\-.]*\d*/', '', $note), ' -.,/');
        $cause = preg_replace('/\s+/', ' ', $cause);

        return [$cause !== '' ? mb_substr($cause, 0, 80) : 'TANPA KETERANGAN', $ncr];
    }

    private function storeIssues(ImportBatch $batch, array $issues): void
    {
        ImportError::where('import_batch_id', $batch->id)->delete();
        foreach (array_chunk($issues, 1000) as $chunk) {
            ImportError::insert(array_map(fn ($i) => [
                'import_batch_id' => $batch->id, 'sheet' => $i['sheet'], 'row_no' => $i['row'],
                'level' => $i['level'], 'message' => $i['message'],
            ], $chunk));
        }
    }
}
