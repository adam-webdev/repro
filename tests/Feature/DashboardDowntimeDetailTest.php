<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardDowntimeDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_clicking_set_up_or_downtime_shows_reasons_machines_and_notes(): void
    {
        $user = User::create(['name' => 'Admin', 'username' => 'adm1', 'password' => 'rahasia123', 'role' => 'produksi', 'is_active' => true]);
        $m1 = Machine::create(['code' => 'CUTP-508', 'type' => 'CUTP']);
        $m2 = Machine::create(['code' => 'CUTP-505', 'type' => 'CUTP']);
        $cat = fn (string $key, string $name) => DB::table('downtime_categories')->insertGetId(['key' => $key, 'name' => $name]);
        $setup = $cat('setup', 'Set Up');
        $downtime = $cat('downtime', 'Downtime');
        $reason = fn (int $catId, string $key, string $name) => DB::table('downtime_reasons')->insertGetId(['downtime_category_id' => $catId, 'key' => $key, 'name' => $name]);
        $awal = $reason($setup, 'seting awal', 'Seting Awal');
        $panjang = $reason($setup, 'ganti panjang', 'Ganti Panjang');
        $bahan = $reason($downtime, 'tunggu bahan', 'Tunggu Bahan');

        $summary = fn (string $date, int $machine, int $reasonId, int $catId, int $minutes) => DB::table('daily_downtime_summaries')->insert([
            'production_date' => $date, 'shift' => 1, 'machine_id' => $machine, 'downtime_reason_id' => $reasonId,
            'downtime_category_id' => $catId, 'minutes' => $minutes, 'occurrences' => 1,
        ]);
        $summary('2026-11-05', $m1->id, $awal, $setup, 90);
        $summary('2026-11-05', $m2->id, $panjang, $setup, 30);
        $summary('2026-11-06', $m1->id, $awal, $setup, 60);
        $summary('2026-11-05', $m1->id, $bahan, $downtime, 120);
        DB::table('daily_machine_summaries')->insert(['production_date' => '2026-11-06', 'shift' => 1, 'machine_id' => $m1->id]);

        $reportId = DB::table('production_reports')->insertGetId([
            'production_date' => '2026-11-05', 'shift' => 1, 'machine_id' => $m1->id, 'order_no' => '408894', 'downtime_note' => 'Ganti PRO, setting ulang cekam',
        ]);
        DB::table('production_downtimes')->insert(['production_report_id' => $reportId, 'downtime_reason_id' => $awal, 'minutes' => 90]);

        // klik batang Set Up tanggal 5 Nov
        $d = $this->actingAs($user)->getJson('/dashboard/downtime-detail?from=2026-11-01&to=2026-11-06&period=day&category=setup&bucket=2026-11-05')
            ->assertOk()->json();
        $this->assertSame(['Set Up', '2026-11-05', '2026-11-05', 2], [$d['category'], $d['from'], $d['to'], $d['total_hours']]);
        $this->assertSame([['Seting Awal', 1.5, 75], ['Ganti Panjang', 0.5, 25]], array_map(fn ($r) => [$r['name'], $r['hours'], $r['share']], $d['reasons']));
        $this->assertSame('CUTP-508', $d['machines'][0]['code']);
        $this->assertSame(['machine' => 'CUTP-508', 'order_no' => '408894', 'note' => 'Ganti PRO, setting ulang cekam', 'minutes' => 90],
            array_intersect_key($d['notes'][0], array_flip(['machine', 'order_no', 'note', 'minutes'])));

        // klik donat Downtime: seluruh periode filter, hanya penyebab kelompok Downtime
        $d = $this->actingAs($user)->getJson('/dashboard/downtime-detail?from=2026-11-01&to=2026-11-06&category=downtime')->assertOk()->json();
        $this->assertSame(['Tunggu Bahan'], array_column($d['reasons'], 'name'));
        $this->assertSame([], $d['notes']); // catatan baris itu hanya punya menit Set Up

        $this->actingAs($user)->getJson('/dashboard/downtime-detail?category=menunggu')->assertStatus(422);
    }
}
