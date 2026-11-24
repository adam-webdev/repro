<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Machine;
use App\Services\DashboardService;
use App\Services\MonitoringDailyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /** Rincian Set Up / Downtime saat batang atau donat diklik. */
    public function downtimeDetail(Request $request, DashboardService $service): JsonResponse
    {
        $request->validate([
            'category' => ['required', Rule::in(array_keys(config('production.downtime_categories')))],
            'bucket' => 'nullable|date',
        ]);

        return response()->json($service->downtimeDetail($service->filters($request->all()), $request->string('category'), $request->input('bucket')));
    }

    /** Monitoring Karu (layout Monitoring Daily): murni dari Form Produksi karu. */
    public function karu(Request $request, MonitoringDailyService $service)
    {
        return view('forms.monitoring', [
            'filters' => $service->filters($request->all()),
            'groups' => $service->groups(),
        ]);
    }

    public function karuData(Request $request, MonitoringDailyService $service)
    {
        return response()->json($service->data($service->filters($request->all())));
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
