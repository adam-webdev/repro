<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Material;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Detail customer: ringkasan, produk (material) yang dipesan, rekap per bulan dan riwayat PRO.
 * Semua angka dari laporan produksi (hasil import), bisa difilter rentang bulan.
 */
class CustomerController extends Controller
{
    public function show(Request $request, Customer $customer): View
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'material_id' => ['nullable', 'integer'],
        ]);

        $from = $request->input('from');
        $to = $request->input('to');
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $materialId = $request->integer('material_id') ?: null;

        $base = fn (): Builder => DB::table('production_reports as r')
            ->where('r.customer_id', $customer->id)->whereNull('r.deleted_at')
            ->when($from, fn ($q) => $q->where('r.production_date', '>=', Carbon::parse($from.'-01')->toDateString()))
            ->when($to, fn ($q) => $q->where('r.production_date', '<=', Carbon::parse($to.'-01')->endOfMonth()->toDateString()));

        $summary = $base()->selectRaw('COUNT(DISTINCT r.order_no) orders, COUNT(*) reports, COUNT(DISTINCT r.material_id) materials,
            COUNT(DISTINCT r.machine_id) machines, SUM(r.ok_qty) ok, SUM(r.nc_qty) nc, SUM(r.reject_qty) rej, SUM(r.ok_kg) ok_kg,
            MIN(r.production_date) first_date, MAX(r.production_date) last_date')->first();

        $products = $base()->leftJoin('materials as m', 'm.id', '=', 'r.material_id')
            ->selectRaw('r.material_id, m.code, COUNT(DISTINCT r.order_no) orders, SUM(r.ok_qty) ok, SUM(r.nc_qty) nc, SUM(r.reject_qty) rej,
                MAX(r.production_date) last_date')
            ->groupBy('r.material_id', 'm.code')->orderByDesc('ok')->get();
        $materials = Material::whereIn('id', $products->pluck('material_id')->filter())->get()->keyBy('id');

        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', r.production_date)" : "DATE_FORMAT(r.production_date, '%Y-%m')";
        $months = $base()->selectRaw("$monthExpr month, COUNT(DISTINCT r.order_no) orders, SUM(r.ok_qty) ok, SUM(r.nc_qty) nc, SUM(r.reject_qty) rej")
            ->groupByRaw($monthExpr)->orderByRaw("$monthExpr DESC")->get();

        $orders = $base()->leftJoin('materials as m', 'm.id', '=', 'r.material_id')
            ->when($materialId, fn ($q) => $q->where('r.material_id', $materialId))
            ->selectRaw('r.order_no, r.material_id, m.code, MIN(r.production_date) first_date, MAX(r.production_date) last_date, COUNT(*) reports,
                COUNT(DISTINCT r.machine_id) machines, SUM(r.ok_qty) ok, SUM(r.nc_qty) nc, SUM(r.reject_qty) rej')
            ->groupBy('r.order_no', 'r.material_id', 'm.code')->orderByDesc('last_date')->orderByDesc('r.order_no')
            ->paginate(25)->withQueryString();

        $range = DB::table('production_reports')->where('customer_id', $customer->id)->whereNull('deleted_at')
            ->selectRaw('MIN(production_date) min, MAX(production_date) max')->first();

        return view('customers.show', compact('customer', 'summary', 'products', 'materials', 'months', 'orders', 'from', 'to', 'materialId', 'range'));
    }
}
