<?php

namespace App\Services\Import;

use OpenSpout\Reader\XLSX\Reader;

/**
 * Membaca workbook Excel dan menghasilkan baris yang sudah dinormalisasi.
 *
 * Format yang dikenali:
 *  - "daily": sheet hasil (header berisi "Kode Mesin" + "Baik") dan sheet downtime
 *             (header berisi "Kode Mesin" + kolom downtime, mis. "Persiapan").
 *             Baris hasil & downtime dipasangkan berdasarkan kunci
 *             tanggal|shift|mesin|order|jam mulai|jam selesai|panjang BB|jumlah BB.
 *  - "sap":   export SAP tanpa header: No Order (500000xxxxxx) | GI bahan (kg) | GR hasil (%).
 */
class WorkbookParser
{
    private const HEADER_SCAN_ROWS = 15;

    private const FIELDS = [
        'customer' => ['customer'],
        'material' => ['kode material', 'material'],
        'date' => ['tanggal', 'tanggal produksi'],
        'order' => ['order', 'no order', 'order no', 'nomor order'],
        'machine' => ['kode mesin', 'mesin'],
        'shift' => ['shift'],
        'start' => ['jam mulai'],
        'end' => ['jam selesai'],
        'raw_length' => ['panjang bahan baku'],
        'raw_qty' => ['jumlah bahan baku'],
        'result_length' => ['panjang hasil'],
        'ok' => ['baik'],
        'nc' => ['nc'],
        'nc_note' => ['keterangan nc'],
        'total' => ['total cuting pcs', 'total cutting pcs'],
        'reject' => ['reject'],
        'reject_note' => ['keterangan reject'],
        'working' => ['total jam kerja', 'jam kerja'],
        'ok_kg' => ['baik kg'],
        'nc_kg' => ['nc kg'],
        'reject_kg' => ['reject kg'],
        'total_kg' => ['total cuting kg', 'total cutting kg'],
    ];

    /** @var array<int, array{level:string, sheet:?string, row:?int, message:string}> */
    public array $issues = [];

    public function detectType(string $path): ?string
    {
        $reader = new Reader;
        $reader->open($path);
        try {
            $firstSheetFirstRow = null;
            $isFirstSheet = true;
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    if ($firstSheetFirstRow === null && $isFirstSheet) {
                        $firstSheetFirstRow = $cells;
                    }
                    $norm = array_map([ExcelValue::class, 'header'], $cells);
                    if (in_array('kode mesin', $norm, true) || in_array('mesin', $norm, true)) {
                        return 'daily';
                    }
                    if (++$n >= self::HEADER_SCAN_ROWS) {
                        break;
                    }
                }
                $isFirstSheet = false;
            }
            $a = ExcelValue::str($firstSheetFirstRow[0] ?? null);
            if ($a !== null && preg_match('/^\d{6,12}$/', $a)) {
                return 'sap';
            }

            return null;
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array<int, array> baris valid & bersih
     */
    public function parseDaily(string $path, array $knownMachines): array
    {
        $this->issues = [];
        $defectCols = config('production.defects');
        $downtimeCols = config('production.downtimes');
        $noteCols = config('production.downtime_notes');
        $freqKeys = collect($downtimeCols)->pluck(2)->filter()->values()->all();

        $results = [];   // baris sheet hasil
        $downtimes = []; // kunci => antrian baris DT
        $hasResultSheet = false;

        $reader = new Reader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = $sheet->getName();
                $kind = null;
                $map = [];      // field => index
                $byHeader = []; // header ternormalisasi => [index,...]
                $scanned = 0;

                foreach ($sheet->getRowIterator() as $rowNo => $row) {
                    $cells = $row->toArray();

                    if ($kind === null) {
                        $norm = array_map([ExcelValue::class, 'header'], $cells);
                        $hasMachine = in_array('kode mesin', $norm, true) || in_array('mesin', $norm, true);
                        if ($hasMachine && in_array('baik', $norm, true)) {
                            $kind = 'result';
                        } elseif ($hasMachine && count(array_intersect($norm, array_keys($downtimeCols))) >= 3) {
                            $kind = 'downtime';
                        }
                        if ($kind) {
                            foreach ($norm as $idx => $h) {
                                $byHeader[$h][] = $idx;
                            }
                            foreach (self::FIELDS as $field => $aliases) {
                                foreach ($aliases as $alias) {
                                    if (isset($byHeader[$alias])) {
                                        $map[$field] = $byHeader[$alias][0];
                                        break;
                                    }
                                }
                            }
                            if ($kind === 'result') {
                                $hasResultSheet = true;
                            }
                            continue;
                        }
                        if (++$scanned >= self::HEADER_SCAN_ROWS) {
                            break; // sheet lain (mis. master mesin) -> lewati
                        }
                        continue;
                    }

                    $get = fn (string $f) => isset($map[$f]) ? ($cells[$map[$f]] ?? null) : null;
                    $date = ExcelValue::date($get('date'));
                    $order = ExcelValue::str($get('order'));
                    $machine = ExcelValue::str($get('machine'));
                    if ($date === null && $order === null && $machine === null) {
                        continue; // baris kosong
                    }

                    $base = [
                        'date' => $date,
                        'shift' => (int) ExcelValue::num($get('shift')),
                        'machine' => $machine ? strtoupper($machine) : null,
                        'order' => $order,
                        'start' => ExcelValue::time($get('start')),
                        'end' => ExcelValue::time($get('end')),
                        'raw_length' => ExcelValue::num($get('raw_length')),
                        'raw_qty' => (int) round(ExcelValue::num($get('raw_qty'))),
                    ];
                    $key = $this->pairKey($base);

                    if ($kind === 'downtime') {
                        $dt = [];
                        foreach ($downtimeCols as $h => $def) {
                            $min = 0.0;
                            foreach ($byHeader[$h] ?? [] as $idx) {
                                $min += ExcelValue::num($cells[$idx] ?? null);
                            }
                            if ($min > 0) {
                                $freq = isset($def[2], $byHeader[$def[2]]) ? (int) ExcelValue::num($cells[$byHeader[$def[2]][0]] ?? null) : null;
                                $dt[$h] = [(int) round($min), $freq ?: null];
                            }
                        }
                        $notes = [];
                        foreach ($noteCols as $h) {
                            foreach ($byHeader[$h] ?? [] as $idx) {
                                $t = ExcelValue::str($cells[$idx] ?? null);
                                if ($t !== null && $t !== '-' && ! is_numeric($t)) {
                                    $notes[] = $t;
                                }
                            }
                        }
                        // kolom angka yang tidak dikenal -> peringatan sekali
                        $downtimes[$key][] = ['row' => $rowNo, 'dt' => $dt, 'note' => $notes ? implode(' | ', array_unique($notes)) : null];

                        continue;
                    }

                    // ---- sheet hasil ----
                    $defects = [];
                    foreach ($defectCols as $h => $label) {
                        if (isset($byHeader[$h])) {
                            $q = (int) round(ExcelValue::num($cells[$byHeader[$h][0]] ?? null));
                            if ($q > 0) {
                                $defects[$h] = $q;
                            }
                        }
                    }
                    $results[] = $base + [
                        'row' => $rowNo,
                        'sheet' => $sheetName,
                        'key' => $key,
                        'customer' => ExcelValue::str($get('customer')),
                        'material' => ($m = ExcelValue::str($get('material'))) ? strtoupper($m) : null,
                        'result_length' => ExcelValue::num($get('result_length')),
                        'ok' => (int) round(ExcelValue::num($get('ok'))),
                        'nc' => (int) round(ExcelValue::num($get('nc'))),
                        'nc_note' => ExcelValue::str($get('nc_note')),
                        'reject' => (int) round(ExcelValue::num($get('reject'))),
                        'reject_note' => ExcelValue::str($get('reject_note')),
                        'total' => (int) round(ExcelValue::num($get('total'))),
                        'working' => isset($map['working']) && $get('working') !== null && $get('working') !== '' ? (int) round(ExcelValue::num($get('working'))) : null,
                        'ok_kg' => ExcelValue::num($get('ok_kg')),
                        'nc_kg' => ExcelValue::num($get('nc_kg')),
                        'reject_kg' => ExcelValue::num($get('reject_kg')),
                        'total_kg' => ExcelValue::num($get('total_kg')),
                        'defects' => $defects,
                    ];
                }
            }
        } finally {
            $reader->close();
        }

        if (! $hasResultSheet) {
            $this->issue('error', null, null, 'Sheet hasil produksi tidak ditemukan (butuh header "Kode Mesin" dan "Baik").');

            return [];
        }

        return $this->finalize($results, $downtimes, $knownMachines);
    }

    private function finalize(array $results, array $downtimes, array $knownMachines): array
    {
        $valid = [];
        $dtSheetPresent = ! empty($downtimes);
        $seen = []; // isi lengkap baris => [sheet, baris]

        $sheets = array_unique(array_column($results, 'sheet'));
        if (count($sheets) > 1) {
            $this->issue('warning', null, null, 'Data hasil dibaca dari '.count($sheets).' sheet: '.implode(', ', $sheets).'. Pastikan tidak ada sheet salinan.');
        }

        foreach ($results as $r) {
            $sheet = $r['sheet'];
            $errors = [];
            if (! $r['date']) {
                $errors[] = 'Tanggal kosong / tidak valid';
            }
            if ($r['shift'] < 1 || $r['shift'] > 3) {
                $errors[] = 'Shift harus 1, 2, atau 3';
            }
            if (! $r['machine']) {
                $errors[] = 'Kode mesin kosong';
            } elseif (! isset($knownMachines[$r['machine']])) {
                $errors[] = "Mesin {$r['machine']} tidak terdaftar di master";
            }
            if (! $r['order']) {
                $errors[] = 'No. order kosong';
            }
            if ($errors) {
                $this->issue('error', $sheet, $r['row'], implode('; ', $errors));

                continue;
            }

            // Baris dobel = SEMUA isi sama persis (salin-tempel). Catatan: order yang sama di mesin & jam yang sama
            // tapi bahan baku berbeda (panjang / jumlah lain) itu wajar dan tetap diimport.
            $dupKey = implode('|', [$r['key'], $r['result_length'], $r['ok'], $r['nc'], $r['reject'], $r['total']]);
            if (isset($seen[$dupKey])) {
                [$s0, $r0] = $seen[$dupKey];
                $this->issue('error', $sheet, $r['row'], "Baris dobel: isinya sama persis dengan baris {$r0}".($s0 !== $sheet ? " (sheet {$s0})" : '')
                    ." (mesin {$r['machine']}, order {$r['order']}). Baris ini tidak diimport. Jika memang 2 kali produksi, bedakan jam / jumlahnya.");

                continue;
            }
            $seen[$dupKey] = [$sheet, $r['row']];

            if (preg_match('/^\d+$/', $r['order']) && strlen($r['order']) < 6) {
                $this->issue('warning', $sheet, $r['row'], "No. order \"{$r['order']}\" mencurigakan (kurang dari 6 digit)");
            }

            $sum = $r['ok'] + $r['nc'] + $r['reject'];
            if ($r['total'] === 0) {
                $r['total'] = $sum;
            } elseif ($r['total'] !== $sum) {
                $this->issue('warning', $sheet, $r['row'], "Total cutting {$r['total']} ≠ Baik+NC+Reject ({$sum})");
            }
            $defectSum = array_sum($r['defects']);
            if ($defectSum > 0 && $defectSum !== $r['reject']) {
                $this->issue('warning', $sheet, $r['row'], "Rincian reject ({$defectSum}) ≠ kolom Reject ({$r['reject']})");
            }

            $r['working'] ??= ExcelValue::minutesBetween($r['start'], $r['end']) ?? 0;

            // pasangkan dengan sheet DT
            $pair = null;
            if (! empty($downtimes[$r['key']])) {
                $pair = array_shift($downtimes[$r['key']]);
            } elseif ($dtSheetPresent) {
                $this->issue('warning', $sheet, $r['row'], 'Tidak ada pasangan di sheet downtime, downtime dianggap 0');
            }
            $r['downtimes'] = $pair['dt'] ?? [];
            $r['downtime_note'] = $pair['note'] ?? null;
            $r['has_dt'] = $pair !== null;

            $dtTotal = array_sum(array_column($r['downtimes'], 0));
            if ($r['working'] > 0 && $dtTotal > $r['working']) {
                $this->issue('warning', $sheet, $r['row'], "Total downtime {$dtTotal} menit > jam kerja {$r['working']} menit");
            }

            unset($r['key']);
            $valid[] = $r;
        }

        foreach ($downtimes as $queue) {
            foreach ($queue as $orphan) {
                $this->issue('warning', 'DT', $orphan['row'], 'Baris downtime tidak punya pasangan di sheet hasil (diabaikan)');
            }
        }

        return $valid;
    }

    /** @return array<int, array{order:string, gi_kg:?float, gr_percent:?float}> */
    public function parseSap(string $path): array
    {
        $this->issues = [];
        $rows = [];
        $reader = new Reader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowNo => $row) {
                    $c = $row->toArray();
                    $a = ExcelValue::str($c[0] ?? null);
                    if ($a === null || ! preg_match('/^\d+$/', $a)) {
                        continue;
                    }
                    $order = (strlen($a) === 12 && str_starts_with($a, '500000')) ? substr($a, 6) : ltrim($a, '0');
                    $gi = isset($c[1]) && $c[1] !== '' && $c[1] !== null ? ExcelValue::num($c[1]) : null;
                    $gr = isset($c[2]) && $c[2] !== '' && $c[2] !== null ? ExcelValue::num($c[2]) : null;
                    if ($gi === null && $gr === null) {
                        $this->issue('warning', $sheet->getName(), $rowNo, "Order {$order}: GI & GR kosong (dilewati)");

                        continue;
                    }
                    if ($gr !== null && $gr > 100) {
                        $this->issue('warning', $sheet->getName(), $rowNo, "Order {$order}: GR {$gr}% melebihi 100%");
                    }
                    $rows[$order] = ['order' => $order, 'gi_kg' => $gi, 'gr_percent' => $gr];
                }
                break; // hanya sheet pertama
            }
        } finally {
            $reader->close();
        }

        return array_values($rows);
    }

    private function pairKey(array $b): string
    {
        return implode('|', [$b['date'], $b['shift'], $b['machine'], $b['order'], $b['start'], $b['end'],
            rtrim(rtrim(number_format($b['raw_length'], 2, '.', ''), '0'), '.'), $b['raw_qty']]);
    }

    private function issue(string $level, ?string $sheet, ?int $row, string $message): void
    {
        $this->issues[] = ['level' => $level, 'sheet' => $sheet, 'row' => $row, 'message' => mb_substr($message, 0, 250)];
    }
}
