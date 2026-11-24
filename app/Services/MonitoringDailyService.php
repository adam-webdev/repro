<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring Karu (layout "Monitoring Daily"): murni dari Form Produksi yang diisi Kepala Regu.
 *
 * Sumber data:
 *  - evaluation_checks (Form Evaluasi): start jam planning & qty planning per mesin
 *  - production_forms (form_date, shift) + production_form_items (mesin, waktu kerja, cutting time, std output, OK / 2nd grade / NG)
 *  - production_form_problems (kind setup|dt, menit) — 1 baris = 1 kejadian (FREQ)
 *  - machines (type = grup mesin dari awalan kode, target_efficiency, daily_target_pcs)
 *
 * Rumus ada di fungsi statis di bawah supaya mudah dicek / diuji.
 */
class MonitoringDailyService
{
    /**
     * @param  array<string, mixed>  $in
     * @return array{group: ?string, date: string, shift: ?int}
     */
    public function filters(array $in): array
    {
        $groups = $this->groups()->all();
        $date = rescue(fn () => ! empty($in['date']) ? Carbon::parse($in['date']) : null, null, false) ?? now();

        return [
            'group' => in_array($in['group'] ?? null, $groups, true) ? $in['group'] : null,
            'date' => $date->toDateString(),
            'shift' => in_array((int) ($in['shift'] ?? 0), [1, 2, 3], true) ? (int) $in['shift'] : null,
        ];
    }

    /** Grup mesin = awalan kode mesin (CUTP, CUTC, CUTL, ...). */
    public function groups(): Collection
    {
        return DB::table('machines')->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');
    }

    /**
     * @param  array{group: ?string, date: string, shift: ?int}  $f
     * @return array<string, mixed>
     */
    public function data(array $f): array
    {
        $date = Carbon::parse($f['date']);
        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();
        $prevDate = $date->copy()->subDay();
        $from = $prevDate->lt($monthStart) ? $prevDate : $monthStart;

        $items = $this->items($from->toDateString(), $monthEnd->toDateString(), $f);
        $problems = $this->problems($from->toDateString(), $monthEnd->toDateString(), $f);
        $planning = $this->planning($from->toDateString(), $monthEnd->toDateString(), $f);
        $day = $date->toDateString();
        $prev = $prevDate->toDateString();
        $machines = $this->machineRows($items->where('date', $day), $problems->where('date', $day), $planning->where('date', $day));

        $kpi = $this->kpi($items->where('date', $day), $planning->where('date', $day));
        $kpiPrev = $this->kpi($items->where('date', $prev), $planning->where('date', $prev));
        $month = $this->monthDays($monthStart, $monthEnd, $items, $problems, $planning);

        return [
            'filters' => $f,
            'group_label' => $f['group'] ? 'Mesin '.$f['group'] : 'Semua mesin',
            'kpi' => $kpi,
            'kpi_prev' => $kpiPrev,
            'kpi_change' => [
                'total_pc' => self::change($kpi['total_pc'], $kpiPrev['total_pc']),
                'efficiency' => self::change($kpi['efficiency'], $kpiPrev['efficiency']),
                'productivity' => self::change($kpi['productivity'], $kpiPrev['productivity']),
            ],
            'month' => [
                'label' => $date->copy()->locale('id')->translatedFormat('F Y'),
                'days' => $month,
                'total_pcs' => array_sum(array_column($month, 'pcs')),
            ],
            'machines' => $machines,
            'ng_top' => $this->ngTop($machines),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ---------------------------------------------------------------- rumus

    /** TOTAL PC satu baris form = OK + 2nd Grade + NG. */
    public static function totalPcs(?int $ok, ?int $secondGrade, ?int $ng): int
    {
        return (int) $ok + (int) $secondGrade + (int) $ng;
    }

    /** Std pcs = Std Output (pcs/jam) × waktu kerja (jam). Null jika std output / waktu kerja tidak diisi. */
    public static function stdPcs(?float $stdOutput, ?int $workingMinutes): ?float
    {
        return $stdOutput > 0 && $workingMinutes > 0 ? $stdOutput * $workingMinutes / 60 : null;
    }

    /** EFFICIENCY / Act÷Std (%) = total pcs ÷ std pcs × 100 (hanya baris yang punya std output). */
    public static function efficiency(float $pcsWithStd, float $stdPcs): ?float
    {
        return $stdPcs > 0 ? round($pcsWithStd / $stdPcs * 100, 1) : null;
    }

    /** PRODUCTIVITY (%) = total pcs ÷ qty planning (Form Evaluasi) × 100. */
    public static function productivity(float $pcs, float $plannedQty): ?float
    {
        return $plannedQty > 0 ? round($pcs / $plannedQty * 100, 1) : null;
    }

    /** Output per jam kerja (pcs/jam) = total pcs ÷ waktu kerja (jam). */
    public static function pcsPerHour(float $pcs, float $workingMinutes): ?float
    {
        return $workingMinutes > 0 ? round($pcs / ($workingMinutes / 60), 1) : null;
    }

    /** Persen a ÷ b dengan pembulatan; null jika pembagi 0. */
    public static function percent(float $a, float $b, int $decimals = 1): ?float
    {
        return $b > 0 ? round($a / $b * 100, $decimals) : null;
    }

    /** Perubahan relatif (%) dibanding hari sebelumnya. */
    public static function change(?float $current, ?float $previous): ?float
    {
        return $current !== null && $previous ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    // ---------------------------------------------------------------- query

    /** Satu baris per mesin per form (shift), sudah dihitung pcs & std pcs-nya. */
    private function items(string $from, string $to, array $f): Collection
    {
        return DB::table('production_form_items as i')
            ->join('production_forms as f', 'f.id', '=', 'i.production_form_id')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->whereNull('f.deleted_at')
            ->whereBetween('f.form_date', [$from, $to])
            ->when($f['group'], fn ($q) => $q->where('m.type', $f['group']))
            ->when($f['shift'], fn ($q) => $q->where('f.shift', $f['shift']))
            ->select('i.id', 'f.form_date', 'i.machine_id', 'm.code', 'm.target_efficiency', 'm.daily_target_pcs',
                'i.ok_qty', 'i.second_grade_qty', 'i.nc_qty', 'i.working_minutes', 'i.cutting_minutes', 'i.std_output')
            ->get()
            ->map(function ($r) {
                $pcs = self::totalPcs($r->ok_qty, $r->second_grade_qty, $r->nc_qty);
                $std = self::stdPcs($r->std_output !== null ? (float) $r->std_output : null, $r->working_minutes);

                return (object) [
                    'date' => substr((string) $r->form_date, 0, 10),
                    'machine_id' => (int) $r->machine_id,
                    'code' => $r->code,
                    'target_efficiency' => $r->target_efficiency !== null ? (float) $r->target_efficiency : null,
                    'daily_target_pcs' => $r->daily_target_pcs !== null ? (int) $r->daily_target_pcs : null,
                    'pcs' => $pcs,
                    'ng' => (int) $r->nc_qty,
                    'work' => (int) $r->working_minutes,
                    'cut' => (int) $r->cutting_minutes,
                    'std_pcs' => $std ?? 0.0,
                    'pcs_with_std' => $std !== null ? $pcs : 0,
                ];
            });
    }

    /** Problem Set Up / DT dari form karu (1 baris = 1 kejadian). */
    private function problems(string $from, string $to, array $f): Collection
    {
        return DB::table('production_form_problems as p')
            ->join('production_form_items as i', 'i.id', '=', 'p.production_form_item_id')
            ->join('production_forms as f', 'f.id', '=', 'i.production_form_id')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('form_options as o', 'o.id', '=', 'p.form_option_id')
            ->whereNull('f.deleted_at')
            ->whereBetween('f.form_date', [$from, $to])
            ->when($f['group'], fn ($q) => $q->where('m.type', $f['group']))
            ->when($f['shift'], fn ($q) => $q->where('f.shift', $f['shift']))
            ->select('f.form_date', 'i.machine_id', 'p.kind', 'o.label', 'p.other_text', 'p.minutes')
            ->get()
            ->map(fn ($r) => (object) [
                'date' => substr((string) $r->form_date, 0, 10),
                'machine_id' => (int) $r->machine_id,
                'kind' => $r->kind === 'setup' ? 'setup' : 'dt',
                'name' => $r->label ?? ('Lainnya: '.($r->other_text ?: '-')),
                'minutes' => (int) $r->minutes,
            ]);
    }

    /**
     * Planning dari Form Evaluasi. Satu pekerjaan = form + mesin + start planning + customer + dimensi;
     * baris cek per jam untuk pekerjaan yang sama tidak dijumlah dua kali (diambil qty terbesar).
     */
    private function planning(string $from, string $to, array $f): Collection
    {
        return DB::table('evaluation_checks as c')
            ->join('evaluation_forms as f', 'f.id', '=', 'c.evaluation_form_id')
            ->join('machines as m', 'm.id', '=', 'c.machine_id')
            ->whereNull('f.deleted_at')
            ->whereBetween('f.form_date', [$from, $to])
            ->where(fn ($q) => $q->whereNotNull('c.planned_qty')->orWhereNotNull('c.planned_start'))
            ->when($f['group'], fn ($q) => $q->where('m.type', $f['group']))
            ->when($f['shift'], fn ($q) => $q->where('f.shift', $f['shift']))
            ->groupBy('f.id', 'f.form_date', 'c.machine_id', 'm.code', 'm.target_efficiency', 'c.planned_start', 'c.customer_id', 'c.dimension')
            ->selectRaw('f.form_date, c.machine_id, m.code, m.target_efficiency, c.planned_start, MAX(c.planned_qty) qty')
            ->get()
            ->map(fn ($r) => (object) [
                'date' => substr((string) $r->form_date, 0, 10),
                'machine_id' => (int) $r->machine_id,
                'code' => $r->code,
                'target_efficiency' => $r->target_efficiency !== null ? (float) $r->target_efficiency : null,
                'start' => $r->planned_start ? substr((string) $r->planned_start, 0, 5) : null,
                'qty' => (int) $r->qty,
            ]);
    }

    // ---------------------------------------------------------------- blok

    /** @return array{total_pc: int, planned_qty: int, efficiency: ?float, productivity: ?float, pcs_per_hour: ?float, ng: int, ng_rate: ?float} */
    private function kpi(Collection $items, Collection $planning): array
    {
        $pcs = (int) $items->sum('pcs');
        $planned = (int) $planning->sum('qty');

        return [
            'total_pc' => $pcs,
            'planned_qty' => $planned,
            'efficiency' => self::efficiency((float) $items->sum('pcs_with_std'), (float) $items->sum('std_pcs')),
            'productivity' => self::productivity($pcs, $planned),
            'pcs_per_hour' => self::pcsPerHour($pcs, (float) $items->sum('work')),
            'ng' => (int) $items->sum('ng'),
            'ng_rate' => self::percent((float) $items->sum('ng'), $pcs, 2),
        ];
    }

    /**
     * Per tanggal dalam bulan: total pcs, target harian, menit setup & downtime.
     * Target harian = Σ per mesin: qty planning (Form Evaluasi); jika tidak ada planning → daily_target_pcs mesin;
     * jika kosong juga → Std Output × waktu kerja dari Form Produksi.
     *
     * @return list<array{date: string, day: int, pcs: int, target: ?int, setup: int, downtime: int}>
     */
    private function monthDays(Carbon $start, Carbon $end, Collection $items, Collection $problems, Collection $planning): array
    {
        $byDate = $items->groupBy('date');
        $probByDate = $problems->groupBy('date');
        $planByDate = $planning->groupBy('date');
        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $rows = $byDate->get($key, collect());
            $plans = $planByDate->get($key, collect())->groupBy('machine_id');
            $target = $rows->groupBy('machine_id')
                ->map(fn (Collection $m, int $id) => $plans->has($id) && $plans[$id]->sum('qty') > 0
                    ? $plans[$id]->sum('qty')
                    : ($m->first()->daily_target_pcs ?? $m->sum('std_pcs')))
                ->sum() + $plans->except($rows->pluck('machine_id')->all())->sum(fn (Collection $p) => $p->sum('qty'));
            $probs = $probByDate->get($key, collect());
            $out[] = [
                'date' => $key,
                'day' => $d->day,
                'pcs' => (int) $rows->sum('pcs'),
                'target' => $target > 0 ? (int) round($target) : null,
                'setup' => (int) $probs->where('kind', 'setup')->sum('minutes'),
                'downtime' => (int) $probs->where('kind', 'dt')->sum('minutes'),
            ];
        }

        return $out;
    }

    /**
     * Per mesin (tanggal terpilih, semua shift digabung): produksi, cutting time %, ET & SU rate, Act/Std, NG rate,
     * dan tabel aktivitas downtime / set up.
     *
     * @return list<array<string, mixed>>
     */
    private function machineRows(Collection $items, Collection $problems, Collection $planning): array
    {
        $defaultTarget = (float) config('production.monitoring.target_efficiency', 85);
        $probByMachine = $problems->groupBy('machine_id');
        $itemsByMachine = $items->groupBy('machine_id');
        $planByMachine = $planning->groupBy('machine_id');

        // Mesin yang punya Form Produksi atau planning; yang belum ada datanya tampil 0.
        $ids = $itemsByMachine->keys()->merge($planByMachine->keys())->unique();

        return $ids->map(function (int $machineId) use ($itemsByMachine, $planByMachine, $probByMachine, $defaultTarget) {
            $rows = $itemsByMachine->get($machineId, collect());
            $plans = $planByMachine->get($machineId, collect());
            $first = $rows->first() ?? $plans->first();
            $probs = $probByMachine->get($machineId, collect());
            $planned = (int) $plans->sum('qty');
            $cut = (int) $rows->sum('cut');
            $dt = (int) $probs->where('kind', 'dt')->sum('minutes');
            $setup = (int) $probs->where('kind', 'setup')->sum('minutes');
            $totalTime = $cut + $dt + $setup;
            $pcs = (int) $rows->sum('pcs');
            $ng = (int) $rows->sum('ng');

            $activities = fn (string $kind) => $probs->where('kind', $kind)->groupBy('name')
                ->map(fn (Collection $g, string $name) => [
                    'name' => $name,
                    'minutes' => (int) $g->sum('minutes'),
                    'freq' => $g->count(),
                    'pct' => self::percent((float) $g->sum('minutes'), $totalTime, 0) ?? 0,
                ])->sortByDesc('minutes')->values()->all();

            return [
                'machine_id' => $machineId,
                'code' => $first->code,
                'pcs' => $pcs,
                'ng' => $ng,
                'planned_qty' => $planned,
                'planned_start' => $plans->pluck('start')->filter()->sort()->first(),
                'productivity' => self::productivity($pcs, $planned),
                'cutting_minutes' => $cut,
                'downtime_minutes' => $dt,
                'setup_minutes' => $setup,
                'cutting_pct' => self::percent($cut, $totalTime) ?? 0,
                'loss_pct' => self::percent($dt + $setup, $totalTime) ?? 0,
                'efficiency' => self::efficiency((float) $rows->sum('pcs_with_std'), (float) $rows->sum('std_pcs')),
                'target_efficiency' => $first->target_efficiency ?? $defaultTarget,
                'ng_rate' => self::percent($ng, $pcs, 2) ?? 0,
                'table' => [
                    'cutting' => ['minutes' => $cut, 'pct' => self::percent($cut, $totalTime, 0) ?? 0],
                    'downtime' => $activities('dt'),
                    'setup' => $activities('setup'),
                    'total' => ['minutes' => $dt + $setup, 'pct' => self::percent($dt + $setup, $totalTime, 0) ?? 0],
                ],
            ];
        })->sortBy('code')->values()->all();
    }

    /**
     * NG tertinggi: NG rate (NG ÷ total pcs) per mesin, 8 terbesar.
     *
     * @param  list<array<string, mixed>>  $machines
     * @return list<array{code: string, ng: int, pcs: int, rate: float}>
     */
    private function ngTop(array $machines): array
    {
        return collect($machines)->filter(fn ($m) => $m['ng'] > 0)
            ->map(fn ($m) => ['code' => $m['code'], 'ng' => $m['ng'], 'pcs' => $m['pcs'], 'rate' => $m['ng_rate']])
            ->sortByDesc('rate')->take(8)->values()->all();
    }
}
