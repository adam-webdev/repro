<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Std output otomatis = median kecepatan (pcs/jam saat mesin jalan) dari data historis,
 * per mesin+material (min. 5 sampel) dan per mesin (cadangan).
 * Std yang diisi manual (source = manual) tidak ditimpa.
 */
class StandardBaseline
{
    public function run(): int
    {
        $byMaterial = [];
        $byMachine = [];
        $rows = DB::table('production_reports')
            ->select('machine_id', 'material_id', 'total_cutting_qty', 'cutting_minutes')
            ->whereNull('deleted_at')->where('cutting_minutes', '>=', 30)->where('total_cutting_qty', '>', 0)
            ->orderBy('id')->cursor();

        foreach ($rows as $r) {
            $rate = $r->total_cutting_qty / $r->cutting_minutes * 60;
            $byMachine[$r->machine_id][] = $rate;
            if ($r->material_id) {
                $byMaterial[$r->machine_id.':'.$r->material_id][] = $rate;
            }
        }

        $manual = DB::table('machine_standards')->where('source', 'manual')
            ->get()->mapWithKeys(fn ($s) => [$s->machine_id.':'.($s->material_id ?? '') => true])->all();

        $now = now();
        $upserts = [];
        foreach ($byMachine as $machineId => $rates) {
            if (count($rates) >= 10 && ! isset($manual[$machineId.':'])) {
                $upserts[] = ['machine_id' => $machineId, 'material_id' => null, 'std_pcs_per_hour' => round($this->median($rates), 2),
                    'source' => 'auto', 'sample_count' => count($rates), 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($byMaterial as $key => $rates) {
            if (count($rates) >= 5 && ! isset($manual[$key])) {
                [$m, $mat] = explode(':', $key);
                $upserts[] = ['machine_id' => (int) $m, 'material_id' => (int) $mat, 'std_pcs_per_hour' => round($this->median($rates), 2),
                    'source' => 'auto', 'sample_count' => count($rates), 'created_at' => $now, 'updated_at' => $now];
            }
        }

        DB::transaction(function () use ($upserts) {
            DB::table('machine_standards')->where('source', 'auto')->delete();
            foreach (array_chunk($upserts, 500) as $chunk) {
                DB::table('machine_standards')->insert($chunk);
            }
        });

        return count($upserts);
    }

    private function median(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
