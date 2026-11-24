<?php

namespace Tests\Feature;

use App\Models\FormOption;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitoringDailyTest extends TestCase
{
    use RefreshDatabase;

    private User $karu;

    private function form(string $date, int $shift, array $items, bool $deleted = false): void
    {
        $formId = DB::table('production_forms')->insertGetId([
            'form_date' => $date, 'shift' => $shift, 'user_id' => $this->karu->id,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
        foreach ($items as $item) {
            $problems = $item['problems'] ?? [];
            unset($item['problems']);
            $itemId = DB::table('production_form_items')->insertGetId($item + ['production_form_id' => $formId]);
            foreach ($problems as $p) {
                DB::table('production_form_problems')->insert($p + ['production_form_item_id' => $itemId]);
            }
        }
    }

    /** Form Evaluasi dengan baris planning: [machine_id, start, qty]. */
    private function planningForm(string $date, array $rows): void
    {
        $formId = DB::table('evaluation_forms')->insertGetId([
            'form_date' => $date, 'shift' => 1, 'user_id' => $this->karu->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($rows as $i => [$machineId, $start, $qty]) {
            DB::table('evaluation_checks')->insert([
                'evaluation_form_id' => $formId, 'seq' => $i + 1, 'machine_id' => $machineId, 'planned_start' => $start, 'planned_qty' => $qty,
            ]);
        }
    }

    public function test_monitoring_daily_numbers_come_from_karu_production_forms(): void
    {
        $this->karu = User::create(['name' => 'Karu', 'username' => 'karu1', 'password' => 'rahasia123', 'role' => 'karu', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin', 'username' => 'adm1', 'password' => 'rahasia123', 'role' => 'produksi', 'is_active' => true]);
        $m508 = Machine::create(['code' => 'CUTP-508', 'type' => 'CUTP', 'target_efficiency' => 90]);
        $m505 = Machine::create(['code' => 'CUTP-505', 'type' => 'CUTP']);
        $m509 = Machine::create(['code' => 'CUTP-509', 'type' => 'CUTP']);
        $laser = Machine::create(['code' => 'CUTL-502', 'type' => 'CUTL']);
        $briefing = FormOption::create(['group' => 'dt', 'label' => 'Briefing']);
        $setup = FormOption::create(['group' => 'setup', 'label' => 'Setting Awal']);

        $this->form('2026-11-20', 1, [
            ['machine_id' => $m508->id, 'working_minutes' => 480, 'cutting_minutes' => 400, 'std_output' => 100,
                'ok_qty' => 700, 'second_grade_qty' => 50, 'nc_qty' => 50, 'problems' => [
                    ['kind' => 'dt', 'form_option_id' => $briefing->id, 'minutes' => 30],
                    ['kind' => 'dt', 'form_option_id' => $briefing->id, 'minutes' => 10],
                    ['kind' => 'dt', 'form_option_id' => null, 'other_text' => 'Tunggu forklift', 'minutes' => 15],
                    ['kind' => 'setup', 'form_option_id' => $setup->id, 'minutes' => 20],
                ]],
            ['machine_id' => $laser->id, 'working_minutes' => 480, 'cutting_minutes' => 480, 'ok_qty' => 999],
        ]);
        $this->form('2026-11-20', 2, [
            ['machine_id' => $m505->id, 'working_minutes' => 480, 'cutting_minutes' => 450, 'ok_qty' => 300, 'nc_qty' => 30],
        ]);
        $this->form('2026-11-19', 1, [
            ['machine_id' => $m508->id, 'working_minutes' => 480, 'cutting_minutes' => 480, 'std_output' => 100, 'ok_qty' => 400],
        ]);
        $this->form('2026-11-20', 3, [['machine_id' => $m508->id, 'working_minutes' => 480, 'ok_qty' => 5000]], deleted: true);

        // Planning (Form Evaluasi): baris cek per jam untuk pekerjaan yang sama tidak dijumlah dua kali
        $this->planningForm('2026-11-20', [
            [$m508->id, '07:00', 1000], [$m508->id, '07:00', 1000],
            [$m505->id, '13:00', 200], [$m505->id, '07:30', 400],
            [$m509->id, '08:00', 300], // ada planning tapi belum ada Form Produksi
            [$laser->id, '07:00', 999],
        ]);

        $d = $this->actingAs($admin)->getJson('/forms/monitoring/data?group=CUTP&date=2026-11-20')->assertOk()->json();

        // KPI: total pc = OK + 2nd grade + NG; efficiency hanya baris yang punya std output; productivity per jam kerja
        // productivity = total pcs ÷ qty planning
        $this->assertEquals(['total_pc' => 1130, 'planned_qty' => 1900, 'efficiency' => 100, 'productivity' => 59.5, 'pcs_per_hour' => 70.6, 'ng' => 80, 'ng_rate' => 7.08], $d['kpi']);
        $this->assertEquals(['total_pc' => 182.5, 'efficiency' => 100, 'productivity' => null], $d['kpi_change']);

        // Bulanan: 30 hari November, target = std output × waktu kerja
        $this->assertCount(30, $d['month']['days']);
        $this->assertSame(1530, $d['month']['total_pcs']);
        $this->assertSame(['date' => '2026-11-20', 'day' => 20, 'pcs' => 1130, 'target' => 1900, 'setup' => 20, 'downtime' => 55], $d['month']['days'][19]);
        $this->assertSame(800, $d['month']['days'][18]['target']); // tanpa planning: std output × waktu kerja

        // Per mesin
        $this->assertSame(['CUTP-505', 'CUTP-508', 'CUTP-509'], array_column($d['machines'], 'code'));
        $this->assertSame([600, '07:30'], [$d['machines'][0]['planned_qty'], $d['machines'][0]['planned_start']]);
        $this->assertSame([0, 300, 0], [$d['machines'][2]['pcs'], $d['machines'][2]['planned_qty'], $d['machines'][2]['cutting_pct']]);
        $m = $d['machines'][1];
        $this->assertEquals([1000, '07:00', 80], [$m['planned_qty'], $m['planned_start'], $m['productivity']]);
        $this->assertSame([84.2, 15.8, 100, 90, 6.25], [$m['cutting_pct'], $m['loss_pct'], $m['efficiency'], $m['target_efficiency'], $m['ng_rate']]);
        $this->assertSame(['minutes' => 400, 'pct' => 84], $m['table']['cutting']);
        $this->assertSame(['name' => 'Briefing', 'minutes' => 40, 'freq' => 2, 'pct' => 8], $m['table']['downtime'][0]);
        $this->assertSame('Lainnya: Tunggu forklift', $m['table']['downtime'][1]['name']);
        $this->assertSame(['minutes' => 75, 'pct' => 16], $m['table']['total']);
        $this->assertNull($d['machines'][0]['efficiency']); // tanpa std output
        $this->assertEquals(85, $d['machines'][0]['target_efficiency']); // bawaan config

        $this->assertSame(['CUTP-505', 'CUTP-508'], array_column($d['ng_top'], 'code'));

        // Tanggal tanpa form: tetap 0, tidak error
        $empty = $this->actingAs($admin)->getJson('/forms/monitoring/data?group=CUTP&date=2026-10-05')->assertOk()->json();
        $this->assertSame(0, $empty['kpi']['total_pc']);
        $this->assertSame([], $empty['machines']);
    }
}
