<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\FormOption;
use App\Models\ProductionForm;
use App\Models\User;
use App\Services\KaruFormService;
use App\Services\MonitoringDailyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Form Rekapan (dulu Form Produksi) yang diisi Karu. */
class ProductionFormController extends Controller
{
    public function __construct(private KaruFormService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        [$query, $from, $to] = $this->filtered($request);

        $forms = $query
            ->with(['user.employee', 'area', 'creator', 'updater', 'items.machine:id,code', 'items.problems', 'items' => fn ($q) => $q->withCount('attachments')])
            ->orderByDesc('form_date')->orderByDesc('shift')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('forms.production.index', [
            'forms' => $forms, 'from' => $from, 'to' => $to,
            'areas' => Area::orderBy('name')->get(['id', 'name']),
            'karus' => User::where('role', 'karu')->orderBy('name')->get(['id', 'name']),
            'canCreate' => $user->hasRole('karu'),
        ]);
    }

    /** Export Excel Form Rekapan sesuai filter: satu baris = satu PRO, link foto di kolom keterangan. */
    public function export(Request $request): StreamedResponse
    {
        [$query, $from, $to] = $this->filtered($request);
        $query->with(['user', 'substituteFor', 'area', 'items.machine:id,code', 'items.problems.option', 'items.attachments'])
            ->orderBy('form_date')->orderBy('shift')->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValuesWithStyle([
                'Tanggal', 'Shift', 'Karu', 'Area', 'Mesin', 'Jenis Mesin', 'No. PRO', 'Dimensi', 'Start Jam Planning', 'Qty Planning (pcs)',
                'Waktu Kerja (mnt)', 'Cutting Time (mnt)', 'Std Output (pcs/jam)', 'Act Output (pcs/jam)', 'Hasil OK (pcs)',
                'Reject (pcs)', 'Hasil NC (pcs)', 'Productivity %', 'Set Up (mnt)', 'Bongkar (mnt)', 'Downtime (mnt)',
                'Rincian Set Up / Bongkar / DT', 'Keterangan',
            ], (new Style)->withFontBold(true)));

            $types = collect(config('production.machine_types'))->map(fn ($t) => $t['label']);
            $query->lazy(200)->each(function (ProductionForm $form) use ($writer, $types) {
                foreach ($form->items as $it) {
                    $minutes = fn (string $kind) => (int) $it->problems->where('kind', $kind)->sum('minutes');
                    $pcs = (int) $it->ok_qty + (int) $it->second_grade_qty + (int) $it->nc_qty;
                    $notes = array_filter([$it->note, ...$it->attachments->map(fn ($a) => 'Foto: '.$a->url())->all()]);
                    $writer->addRow(Row::fromValues([
                        $form->form_date->format('Y-m-d'), $form->shift, $form->karuLabel(), $form->area?->name ?? 'Semua area',
                        $it->machine?->code, $types[$it->machine_type] ?? $it->machine_type, $it->order_no, $it->dimension,
                        $it->planned_start ? substr($it->planned_start, 0, 5) : null, $it->planned_qty,
                        $it->working_minutes, $it->cutting_minutes,
                        $it->std_output !== null ? (float) $it->std_output : null, $it->act_output !== null ? (float) $it->act_output : null,
                        $it->ok_qty, $it->second_grade_qty, $it->nc_qty,
                        MonitoringDailyService::productivity($pcs, (float) $it->planned_qty),
                        $minutes('setup'), $minutes('bongkar'), $minutes('dt'),
                        $it->problems->map(fn ($p) => (['setup' => 'Set Up', 'bongkar' => 'Bongkar'][$p->kind] ?? 'DT').': '
                            .FormOption::display($p->option, $p->other_text).' ('.($p->minutes ?? 0).' mnt)')->join("\n"),
                        $notes ? implode("\n", $notes) : null,
                    ]));
                }
            });
            $writer->close();
        }, "form-rekapan_{$from}_{$to}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function create(Request $request)
    {
        return view('forms.production.form', ['form' => null, 'config' => $this->service->formConfig(), 'payload' => null]);
    }

    public function store(Request $request)
    {
        $form = $this->service->saveProduction($request);

        return response()->json(['redirect' => route('forms.production.show', $form), 'message' => 'Form rekapan tersimpan.']);
    }

    public function show(Request $request, ProductionForm $form)
    {
        $this->authorizeView($request, $form);
        $form->load(['user.employee', 'area', 'creator', 'updater', 'items.machine', 'items.problems.option', 'items.attachments']);

        return view('forms.production.show', [
            'form' => $form,
            'canEdit' => KaruFormService::canEdit($request->user(), $form),
            'logs' => ActivityLog::where('subject_type', 'production_form')->where('subject_id', $form->id)->latest('id')->get(),
        ]);
    }

    public function edit(Request $request, ProductionForm $form)
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403, 'Hanya Karu pembuat form atau Admin Master yang bisa mengubah form ini.');
        $form->load(['items.problems', 'items.attachments', 'user']);

        return view('forms.production.form', [
            'form' => $form,
            'config' => $this->service->formConfig(),
            'payload' => [
                'form_date' => $form->form_date->toDateString(),
                'shift' => $form->shift,
                'area_id' => $form->area_id,
                'substitute_for_id' => $form->substitute_for_id,
                'items' => $form->items->map(fn ($it) => [
                    'machine_type' => $it->machine_type, 'machine_id' => $it->machine_id, 'order_no' => $it->order_no, 'dimension' => $it->dimension,
                    'planned_start' => $it->planned_start ? substr($it->planned_start, 0, 5) : '', 'planned_qty' => $it->planned_qty,
                    'working_minutes' => $it->working_minutes, 'cutting_minutes' => $it->cutting_minutes,
                    'std_output' => $it->std_output !== null ? (float) $it->std_output : null,
                    'act_output' => $it->act_output !== null ? (float) $it->act_output : null,
                    'ok_qty' => $it->ok_qty, 'second_grade_qty' => $it->second_grade_qty, 'nc_qty' => $it->nc_qty, 'note' => $it->note,
                    'problems' => $it->problems->map(fn ($p) => [
                        'kind' => $p->kind, 'option_id' => $p->form_option_id ?? ($p->other_text ? 'other' : ''),
                        'other_text' => $p->other_text, 'minutes' => $p->minutes,
                    ])->values(),
                    'photos' => $it->attachments->map(fn ($a) => ['id' => $a->id, 'url' => $a->url()])->values(),
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, ProductionForm $form)
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403);
        $this->service->saveProduction($request, $form);

        return response()->json(['redirect' => route('forms.production.show', $form), 'message' => 'Perubahan tersimpan.']);
    }

    public function destroy(Request $request, ProductionForm $form)
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403);
        $this->service->delete($request, $form);

        return redirect()->route('forms.production.index')->with('success', 'Form dihapus. Riwayatnya tetap tersimpan di log aktivitas.');
    }

    /**
     * Query Form Rekapan sesuai filter halaman daftar (karu hanya miliknya sendiri).
     *
     * @return array{0: Builder<ProductionForm>, 1: string, 2: string}
     */
    private function filtered(Request $request): array
    {
        $user = $request->user();
        $to = $request->input('to') ?: now()->toDateString();
        $from = $request->input('from') ?: now()->subDays(6)->toDateString();

        $query = ProductionForm::query()
            ->whereBetween('form_date', [$from, $to])
            ->when($user->role === 'karu', fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('shift'), fn ($q) => $q->where('shift', $request->integer('shift')))
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', $request->integer('area_id')))
            ->when($request->filled('user_id') && $user->role !== 'karu', fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('machine_id'), fn ($q) => $q->whereHas('items', fn ($i) => $i->where('machine_id', $request->integer('machine_id'))));

        return [$query, $from, $to];
    }

    private function authorizeView(Request $request, ProductionForm $form): void
    {
        $u = $request->user();
        abort_if($u->role === 'karu' && $form->user_id !== $u->id, 403, 'Anda hanya bisa melihat form milik Anda sendiri.');
        abort_if($form->trashed() && ! $u->isMaster(), 404);
    }
}
