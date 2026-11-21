<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Machine;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $service)
    {
        return view('dashboard', $this->filterOptions() + [
            'filters' => $service->filters($request->all()),
            'range' => DB::table('daily_machine_summaries')->selectRaw('MIN(production_date) min, MAX(production_date) max')->first(),
            'lastImport' => DB::table('import_batches')->where('status', 'done')->max('committed_at'),
        ]);
    }

    public function data(Request $request, DashboardService $service)
    {
        return response()->json($service->data($service->filters($request->all())));
    }

    /** Monitoring Karu: isian Form Produksi & Form Evaluasi, dibandingkan dengan data import admin. */
    public function karu(Request $request, DashboardService $service)
    {
        return view('forms.monitoring', $this->filterOptions() + [
            'filters' => $service->filters($this->karuInput($request)),
            'range' => $this->karuRange(),
        ]);
    }

    public function karuData(Request $request, DashboardService $service)
    {
        return response()->json($service->karuData($service->filters($this->karuInput($request))));
    }

    /** Form karu bisa lebih baru dari data import terakhir, jadi bawaannya sampai hari ini. */
    private function karuInput(Request $request): array
    {
        $in = $request->all();
        $in['to'] = $in['to'] ?? now()->toDateString();

        return $in;
    }

    private function karuRange(): object
    {
        $p = DB::table('production_forms')->whereNull('deleted_at')->selectRaw('MIN(form_date) min, MAX(form_date) max')->first();
        $e = DB::table('evaluation_forms')->whereNull('deleted_at')->selectRaw('MIN(form_date) min, MAX(form_date) max')->first();
        $min = collect([$p->min, $e->min])->filter()->min();

        return (object) ['min' => $min, 'max' => now()->toDateString()];
    }

    private function filterOptions(): array
    {
        return [
            'areas' => Area::orderBy('name')->get(['id', 'name']),
            'machines' => Machine::orderBy('code')->get(['id', 'code', 'area_id', 'building']),
            'buildings' => Machine::whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
        ];
    }
}
