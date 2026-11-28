<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\EvaluationForm;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\ProductionForm;
use App\Models\User;
use App\Services\SummaryBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data dummy untuk mencoba Form Daily Supervisor & Form Rekapan (khusus lokal / testing).
 * Semua data ditandai "[DUMMY]" dan dihapus dulu setiap seeder dijalankan ulang.
 *
 *   php artisan db:seed --class=DummyKaruFormSeeder
 */
class DummyKaruFormSeeder extends Seeder
{
    public const TAG = '[DUMMY]';

    private const PROBLEMS = [
        'Tidak ada', 'Tidak ada', 'Tidak ada', 'Burry di ujung potongan', 'Panjang kurang 0,5 mm, setting stopper',
        'Champer miring, pahat diganti', 'Pipa penyok dari supplier', 'Cekam longgar, sudah dikencangkan',
    ];

    public function run(): void
    {
        $this->clear();

        $karus = User::where('role', 'karu')->where('is_active', true)->orderBy('name')->get();
        $areas = Area::orderBy('name')->get();
        if ($karus->isEmpty() || $areas->isEmpty()) {
            $this->command?->warn('Butuh data karu & area. Jalankan DatabaseSeeder dulu.');

            return;
        }

        $opts = FormOption::where('is_active', true)->get()->groupBy('group');
        $visualPipe = FormOption::visualPipeId();
        $customers = Customer::inRandomOrder()->limit(15)->pluck('id');
        $machinesByArea = Machine::where('is_active', true)->get()->groupBy('area_id');
        $dates = collect(range(2, 0))->map(fn ($d) => now()->subDays($d)->startOfDay());

        // karu tanpa area tugas mengisi sisa slot, supaya karu tetap tidak muncul di area lain
        $freeKarus = $karus->whereNull('area_id')->values();
        $freeKarus = $freeKarus->isEmpty() ? $karus : $freeKarus;
        $karuIndex = 0;
        $nextKaru = function () use ($freeKarus, &$karuIndex) {
            return $freeKarus[$karuIndex++ % $freeKarus->count()];
        };

        $evalForms = 0;
        $checks = 0;
        foreach ($dates as $date) {
            foreach ($areas as $area) {
                $machines = $machinesByArea->get($area->id, collect())->values();
                if ($machines->isEmpty()) {
                    continue;
                }
                // 3 karu per area per hari: 2 di shift 1, 1 di shift 2
                $areaKarus = $karus->where('area_id', $area->id)->values();
                foreach ([1, 1, 2] as $n => $shift) {
                    // karu tetap area ini dulu (sesuai area tugas), sisanya karu lain bergiliran
                    $karu = $areaKarus[$n] ?? $nextKaru();
                    $form = EvaluationForm::create([
                        'form_date' => $date->toDateString(), 'shift' => $shift, 'area_id' => $area->id,
                        'user_id' => $karu->id, 'employee_id' => $karu->employee_id, 'created_by' => $karu->id, 'updated_by' => $karu->id,
                    ]);
                    $evalForms++;
                    $times = $this->checkTimes($shift, random_int(1, 3));
                    foreach ($times as $seq => $time) {
                        $this->evaluationCheck($form, $seq + 1, $time, $machines->random(), $customers, $opts, $visualPipe, $karu);
                        $checks++;
                    }
                }
            }
        }

        $prodForms = $this->productionForms($dates, $karus, $machinesByArea, $areas, $opts);

        SummaryBuilder::bumpCache();
        $this->command?->info("Dummy dibuat: {$evalForms} Form Daily Supervisor ({$checks} pengecekan) & {$prodForms} Form Rekapan.");
    }

    /** Hapus data dummy sebelumnya (form, baris, foto dummy). */
    private function clear(): void
    {
        $evalIds = DB::table('evaluation_checks')->where('problem', 'like', self::TAG.'%')->distinct()->pluck('evaluation_form_id');
        $checkIds = DB::table('evaluation_checks')->whereIn('evaluation_form_id', $evalIds)->pluck('id');
        DB::table('attachments')->where('attachable_type', 'evaluation_check')->whereIn('attachable_id', $checkIds)->delete();
        EvaluationForm::withTrashed()->whereIn('id', $evalIds)->forceDelete();

        $prodIds = DB::table('production_form_items')->where('note', 'like', self::TAG.'%')->distinct()->pluck('production_form_id');
        ProductionForm::withTrashed()->whereIn('id', $prodIds)->forceDelete();
    }

    /** Jam cek acak dalam shift, urut naik (shift 2 bisa lewat tengah malam). */
    private function checkTimes(int $shift, int $count): array
    {
        $start = $shift === 1 ? 7 * 60 : 18 * 60;
        $minutes = collect(range(1, $count))->map(fn () => $start + random_int(20, 10 * 60))->sort()->values();

        return $minutes->map(fn ($m) => sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60))->all();
    }

    private function evaluationCheck(EvaluationForm $form, int $seq, string $time, Machine $machine, Collection $customers, Collection $opts, ?int $visualPipe, User $karu): void
    {
        $od = [10, 12.7, 15.9, 19.05, 25.4][array_rand([10, 12.7, 15.9, 19.05, 25.4])];
        $length = [450, 862, 1200, 600][array_rand([450, 862, 1200, 600])];
        $dev = fn () => round($od + random_int(-3, 3) / 100, 2);
        $compliance = $opts->get('compliance', collect())->where('id', '!=', $visualPipe)->random(min(2, $opts->get('compliance', collect())->count() - 1))->pluck('id')->all();
        $withPipe = $visualPipe && random_int(1, 3) === 1;
        if ($withPipe) {
            $compliance[] = $visualPipe;
        }
        $visual = $opts->get('visual', collect());
        $visualId = random_int(1, 5) === 1 ? $visual->where('label', '!=', 'OK')->random()?->id : $visual->firstWhere('label', 'OK')?->id;

        $check = $form->checks()->create([
            'seq' => $seq,
            'check_time' => $time,
            'machine_id' => $machine->id,
            'customer_id' => $customers->isNotEmpty() ? $customers->random() : null,
            'dimension' => 'Ø'.str_replace('.', ',', (string) $od).' × 1,2 × '.$length,
            'd1' => $dev(), 'd2' => $dev(), 'd3' => $dev(), 'd4' => $dev(),
            'thickness_mm' => 1.2, 'ib_height_mm' => round(random_int(-10, 30) / 100, 2),
            'p1' => $length + random_int(-5, 5) / 10, 'p2' => $length + random_int(-5, 5) / 10, 'p3' => $length + random_int(-5, 5) / 10,
            'visual_option_id' => $visualId,
            'actual_cutting' => random_int(80, 900),
            'problem' => self::TAG.' '.self::PROBLEMS[array_rand(self::PROBLEMS)],
            'pipe_photo_url' => $withPipe ? 'https://picsum.photos/seed/pipa'.$form->id.'-'.$seq.'/800/600' : null,
        ]);
        $check->compliances()->sync($compliance);
        $jig = $opts->get('jig', collect())->isNotEmpty() ? $opts->get('jig')->random() : null;
        if ($jig) {
            $check->jigs()->sync([$jig->id => ['size' => in_array($jig->label, FormOption::JIG_WITH_SIZE, true) ? 'Ø'.str_replace('.', ',', (string) $od) : null]]);
        }

        // sebagian pengecekan punya "foto" evidence (file dummy tidak ada; hanya untuk mencoba link di export)
        if (random_int(1, 3) === 1) {
            Attachment::create([
                'attachable_type' => 'evaluation_check', 'attachable_id' => $check->id, 'user_id' => $karu->id,
                'path' => 'evidence/dummy/dummy.jpg', 'original_name' => 'dummy.jpg', 'mime' => 'image/jpeg',
            ]);
        }
    }

    /** Form Rekapan dengan planning: tiap karu satu form per hari terakhir, 1–3 PRO. */
    private function productionForms(Collection $dates, Collection $karus, Collection $machinesByArea, Collection $areas, Collection $opts): int
    {
        $count = 0;
        $dt = $opts->get('dt', collect())->whereNull('machine_type')->values();
        $setup = $opts->get('setup', collect());
        foreach ($dates as $date) {
            foreach ($karus as $i => $karu) {
                $area = $areas->firstWhere('id', $karu->area_id) ?? $areas[$i % $areas->count()];
                $machines = $machinesByArea->get($area->id, collect());
                if ($machines->isEmpty()) {
                    continue;
                }
                $shift = $i % 2 + 1;
                $form = ProductionForm::create([
                    'form_date' => $date->toDateString(), 'shift' => $shift, 'area_id' => $area->id,
                    'user_id' => $karu->id, 'employee_id' => $karu->employee_id, 'created_by' => $karu->id, 'updated_by' => $karu->id,
                ]);
                $count++;
                foreach (range(0, random_int(0, 2)) as $sort) {
                    $machine = $machines->random();
                    $work = random_int(300, 660);
                    $cut = (int) round($work * random_int(70, 95) / 100);
                    $std = random_int(80, 200);
                    $plan = (int) round($std * $work / 60);
                    $ok = (int) round($plan * random_int(70, 105) / 100);
                    $reject = random_int(0, 20);
                    $item = $form->items()->create([
                        'machine_id' => $machine->id, 'machine_type' => Machine::formType($machine->code), 'order_no' => (string) random_int(400000, 499999),
                        'dimension' => 'Ø'.random_int(10, 25).' × 1,2 × '.random_int(400, 1200),
                        'planned_start' => sprintf('%02d:%02d', $shift === 1 ? 7 + $sort * 3 : 18 + $sort * 3, [0, 15, 30][random_int(0, 2)]),
                        'planned_qty' => $plan, 'working_minutes' => $work, 'cutting_minutes' => $cut, 'std_output' => $std,
                        'act_output' => round($ok / ($work / 60), 1), 'ok_qty' => $ok, 'second_grade_qty' => $reject,
                        'reject_reason' => $reject ? ['Burry', 'Penyok', 'Panjang kurang', 'Gores'][random_int(0, 3)] : null, 'nc_qty' => random_int(0, 40),
                        'note' => self::TAG, 'sort' => $sort,
                    ]);
                    // Set Up + DT tidak melebihi selisih waktu kerja − cutting time
                    $spare = $work - $cut;
                    $setupMinutes = min(random_int(10, 40), $spare);
                    if ($setup->isNotEmpty() && $setupMinutes > 0) {
                        $item->problems()->create(['kind' => 'setup', 'form_option_id' => $setup->random()->id, 'minutes' => $setupMinutes]);
                    }
                    $dtMinutes = $spare - $setupMinutes - random_int(0, 10);
                    if ($dt->isNotEmpty() && $dtMinutes > 0) {
                        $item->problems()->create(['kind' => 'dt', 'form_option_id' => $dt->random()->id, 'minutes' => $dtMinutes]);
                    }
                }
            }
        }

        return $count;
    }
}
