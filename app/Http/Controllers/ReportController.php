<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Machine;
use App\Models\ProductionReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class ReportController extends Controller
{
    private function query(Request $request): array
    {
        $to = $request->input('to') ?: (ProductionReport::max('production_date') ?? now()->toDateString());
        $to = Carbon::parse($to)->toDateString();
        $from = $request->input('from') ?: Carbon::parse($to)->subDays(6)->toDateString();

        $q = ProductionReport::query()
            ->whereBetween('production_date', [$from, $to])
            ->when($request->filled('shift'), fn ($q) => $q->where('shift', $request->integer('shift')))
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', $request->integer('area_id')))
            ->when($request->filled('machine_id'), fn ($q) => $q->where('machine_id', $request->integer('machine_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = trim((string) $request->input('q'));
                $q->where(fn ($w) => $w->where('order_no', 'like', $s.'%')
                    ->orWhereHas('material', fn ($m) => $m->where('code', 'like', '%'.$s.'%'))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$s.'%')));
            });

        return [$q, $from, $to];
    }

    public function index(Request $request)
    {
        [$q, $from, $to] = $this->query($request);
        $reports = (clone $q)->with(['machine:id,code', 'customer:id,name', 'material:id,code,od_mm,thickness_mm,length_mm'])
            ->orderByDesc('production_date')->orderBy('shift')->orderBy('machine_id')->orderBy('start_time')
            ->paginate(50)->withQueryString();

        return view('reports.index', [
            'reports' => $reports,
            'from' => $from,
            'to' => $to,
            'areas' => Area::orderBy('name')->get(['id', 'name']),
            'machines' => Machine::orderBy('code')->get(['id', 'code']),
            'totals' => (clone $q)->toBase()->selectRaw('COUNT(*) c, SUM(ok_qty) ok, SUM(nc_qty) nc, SUM(reject_qty) rej, SUM(downtime_minutes) dt')->first(),
        ]);
    }

    public function export(Request $request)
    {
        [$q, $from, $to] = $this->query($request);
        @set_time_limit(0);

        return response()->streamDownload(function () use ($q) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValuesWithStyle([
                'Tanggal', 'Shift', 'Mesin', 'Area', 'Gedung', 'Order', 'Customer', 'Kode Material',
                'Jam Mulai', 'Jam Selesai', 'Panjang Bahan Baku', 'Jumlah Bahan Baku', 'Panjang Hasil', 'Baik', 'NC',
                'Keterangan NC', 'Reject', 'Keterangan Reject', 'Total Cutting', 'Jam Kerja (mnt)', 'Downtime (mnt)',
                'Set Up (mnt)', 'Cutting Time (mnt)', 'Cutting Time %', 'Baik kg', 'NC kg', 'Reject kg', 'Total kg',
                'Keterangan Downtime', 'Diinput oleh', 'Terakhir diperbarui oleh', 'Terakhir diperbarui',
            ], (new Style)->withFontBold(true)));

            $q->with(['machine:id,code', 'area:id,name', 'customer:id,name', 'material:id,code', 'creator:id,name', 'updater:id,name'])
                ->orderBy('production_date')->orderBy('shift')->orderBy('machine_id')->orderBy('id')
                ->lazy(1000)->each(function ($r) use ($writer) {
                    $writer->addRow(Row::fromValues([
                        $r->production_date->format('Y-m-d'), $r->shift, $r->machine?->code, $r->area?->name, $r->building,
                        $r->order_no, $r->customer?->name, $r->material?->code,
                        substr((string) $r->start_time, 0, 5), substr((string) $r->end_time, 0, 5),
                        (float) $r->raw_length, $r->raw_qty, (float) $r->result_length, $r->ok_qty, $r->nc_qty, $r->nc_note,
                        $r->reject_qty, $r->reject_note, $r->total_cutting_qty, $r->working_minutes, $r->downtime_minutes,
                        $r->setup_minutes, $r->cutting_minutes,
                        $r->working_minutes ? round($r->cutting_minutes / $r->working_minutes, 4) : null,
                        (float) $r->ok_kg, (float) $r->nc_kg, (float) $r->reject_kg, (float) $r->total_kg, $r->downtime_note,
                        $r->creator?->name, $r->updater?->name, $r->updated_at?->format('Y-m-d H:i'),
                    ]));
                });
            $writer->close();
        }, "produksi_{$from}_{$to}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function show(ProductionReport $report)
    {
        $report->load(['machine', 'customer', 'material', 'defects.type', 'downtimes.reason.category', 'batch.user', 'creator', 'updater']);
        $history = ActivityLog::where('subject_type', 'production_data')
            ->where('subject_key', $report->production_date->toDateString().'|'.$report->shift)
            ->latest('id')->limit(10)->get()
            ->map(fn ($l) => [
                'action' => $l->actionLabel(), 'who' => $l->user_name, 'role' => $l->roleLabel(),
                'card' => $l->employee_id ? route('employees.card', $l->employee_id) : null,
                'at' => $l->created_at->format('d/m/Y H:i'),
                'info' => collect($l->summary())->except('File')->map(fn ($v, $k) => "$k $v")->join(' · '),
                'file' => $l->summary()['File'] ?? null,
            ]);

        return response()->json([
            'id' => $report->id,
            'date' => $report->production_date->format('d/m/Y'),
            'shift' => $report->shift,
            'machine' => $report->machine?->code,
            'order' => $report->order_no,
            'customer' => $report->customer?->name,
            'material' => $report->material?->code,
            'size' => $report->material?->size_label,
            'defects' => $report->defects->map(fn ($d) => ['name' => $d->type->name, 'qty' => $d->qty])->sortByDesc('qty')->values(),
            'downtimes' => $report->downtimes->map(fn ($d) => [
                'name' => $d->reason->name, 'category' => $d->reason->category->name,
                'minutes' => $d->minutes, 'frequency' => $d->frequency,
            ])->sortByDesc('minutes')->values(),
            'downtime_note' => $report->downtime_note,
            'imported_by' => $report->batch?->user?->name ?? 'Import sistem',
            'imported_at' => $report->batch?->committed_at?->format('d/m/Y H:i'),
            'file' => $report->batch?->file_name,
            'created_by' => $report->creator?->name ?? 'Sistem (terminal)',
            'created_card' => $report->creator?->employee_id ? route('employees.card', $report->creator->employee_id) : null,
            'created_at' => $report->created_at?->format('d/m/Y H:i'),
            'updated_by' => $report->updater?->name ?? 'Sistem (terminal)',
            'updated_card' => $report->updater?->employee_id ? route('employees.card', $report->updater->employee_id) : null,
            'updated_at' => $report->updated_at?->format('d/m/Y H:i'),
            'history' => $history,
        ]);
    }
}
