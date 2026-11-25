<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Machine;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_detail_shows_products_and_pro_history_filtered_by_month(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'adm1', 'password' => 'rahasia123', 'role' => 'produksi', 'is_active' => true]);
        $customer = Customer::create(['name' => 'RINNAI INDONESIA']);
        $other = Customer::create(['name' => 'CUSTOMER LAIN']);
        $machine = Machine::create(['code' => 'CUTP-508', 'type' => 'CUTP']);
        $pipe = Material::create(['code' => 'PIPA-A']);
        $tube = Material::create(['code' => 'PIPA-B']);

        $report = fn (string $date, string $order, Customer $c, Material $m, int $ok) => DB::table('production_reports')->insert([
            'production_date' => $date, 'shift' => 1, 'machine_id' => $machine->id, 'order_no' => $order,
            'customer_id' => $c->id, 'material_id' => $m->id, 'ok_qty' => $ok, 'nc_qty' => 0, 'reject_qty' => 0,
        ]);
        $report('2026-10-05', '440001', $customer, $pipe, 100);
        $report('2026-10-06', '440001', $customer, $pipe, 50);
        $report('2026-11-03', '450002', $customer, $tube, 70);
        $report('2026-11-03', '450009', $other, $pipe, 999);

        $this->actingAs($admin)->get(route('customers.show', $customer))->assertOk()
            ->assertSeeInOrder(['RINNAI INDONESIA', 'Jumlah PRO', '2', 'Produk yang dipesan', 'PIPA-A', 'PIPA-B', 'Riwayat PRO', '450002', '440001'])
            ->assertSee('220 pcs')
            ->assertDontSee('450009');

        // filter November: hanya PRO 450002
        $this->actingAs($admin)->get(route('customers.show', [$customer, 'from' => '2026-11', 'to' => '2026-11']))->assertOk()
            ->assertSee('450002')->assertDontSee('440001')->assertSee('70 pcs');

        // klik produk PIPA-A: riwayat PRO hanya material itu
        $this->actingAs($admin)->get(route('customers.show', [$customer, 'material_id' => $pipe->id]))->assertOk()
            ->assertSee('440001')->assertDontSee('>450002<', false);

        $this->actingAs($admin)->get(route('customers.show', [$customer, 'from' => 'bukan-bulan']))->assertSessionHasErrors('from');
    }

    public function test_karu_cannot_open_customer_detail(): void
    {
        $karu = User::create(['name' => 'Karu', 'username' => 'ka1', 'password' => 'rahasia123', 'role' => 'karu', 'is_active' => true]);
        $customer = Customer::create(['name' => 'RINNAI INDONESIA']);

        $this->actingAs($karu)->get(route('customers.show', $customer))->assertForbidden();
    }
}
