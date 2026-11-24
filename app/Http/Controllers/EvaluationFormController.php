<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\EvaluationForm;
use App\Models\User;
use App\Services\Export\EvaluationSheetExport;
use App\Services\KaruFormService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EvaluationFormController extends Controller
{
    public function __construct(private KaruFormService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $to = $request->input('to') ?: now()->toDateString();
        $from = $request->input('from') ?: now()->subDays(6)->toDateString();

        $forms = EvaluationForm::query()
            ->with(['user.employee', 'area', 'creator', 'updater', 'checks.machine:id,code', 'checks.visual', 'checks' => fn ($q) => $q->withCount('attachments')])
            ->whereBetween('form_date', [$from, $to])
            ->when($user->role === 'karu', fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('shift'), fn ($q) => $q->where('shift', $request->integer('shift')))
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', $request->integer('area_id')))
            ->when($request->filled('user_id') && $user->role !== 'karu', fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('machine_id'), fn ($q) => $q->whereHas('checks', fn ($i) => $i->where('machine_id', $request->integer('machine_id'))))
            ->orderByDesc('form_date')->orderByDesc('shift')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('forms.evaluation.index', [
            'forms' => $forms, 'from' => $from, 'to' => $to,
            'areas' => Area::orderBy('name')->get(['id', 'name']),
            'karus' => User::where('role', 'karu')->orderBy('name')->get(['id', 'name']),
            'canCreate' => $user->hasRole('karu'),
        ]);
    }

    public function create()
    {
        return view('forms.evaluation.form', ['form' => null, 'config' => $this->service->formConfig(), 'payload' => null]);
    }

    public function store(Request $request)
    {
        $form = $this->service->saveEvaluation($request);

        return response()->json(['redirect' => route('forms.evaluation.show', $form), 'message' => 'Form evaluasi tersimpan.']);
    }

    public function show(Request $request, EvaluationForm $form)
    {
        $u = $request->user();
        abort_if($u->role === 'karu' && $form->user_id !== $u->id, 403, 'Anda hanya bisa melihat form milik Anda sendiri.');
        abort_if($form->trashed() && ! $u->isMaster(), 404);
        $form->load(['user.employee', 'area', 'creator', 'updater', 'checks.machine', 'checks.customer', 'checks.jig', 'checks.visual', 'checks.compliances', 'checks.attachments']);

        return view('forms.evaluation.show', [
            'form' => $form,
            'canEdit' => KaruFormService::canEdit($u, $form),
            'logs' => ActivityLog::where('subject_type', 'evaluation_form')->where('subject_id', $form->id)->latest('id')->get(),
        ]);
    }

    /** Unduh form dalam template kertas PDR/PP/13 yang sudah terisi (.xlsx). */
    public function export(Request $request, EvaluationForm $form, EvaluationSheetExport $export)
    {
        $u = $request->user();
        abort_if($u->role === 'karu' && $form->user_id !== $u->id, 403, 'Anda hanya bisa mengunduh form milik Anda sendiri.');
        abort_if($form->trashed() && ! $u->isMaster(), 404);

        $book = $export->build($form);

        return response()->streamDownload(function () use ($book) {
            IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, $export->filename($form), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function edit(Request $request, EvaluationForm $form)
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403, 'Hanya Karu pembuat form atau Admin Master yang bisa mengubah form ini.');
        $form->load(['checks.customer', 'checks.compliances', 'checks.attachments', 'user']);
        $opt = fn ($id, $other) => $id ?? ($other ? 'other' : '');
        $num = fn ($v) => $v !== null ? (float) $v : null;

        return view('forms.evaluation.form', [
            'form' => $form,
            'config' => $this->service->formConfig(),
            'payload' => [
                'form_date' => $form->form_date->toDateString(),
                'shift' => $form->shift,
                'area_id' => $form->area_id,
                'checks' => $form->checks->map(fn ($c) => [
                    'check_time' => $c->check_time ? substr($c->check_time, 0, 5) : '',
                    'machine_id' => $c->machine_id, 'customer' => $c->customer?->name, 'dimension' => $c->dimension,
                    'planned_start' => $c->planned_start ? substr($c->planned_start, 0, 5) : '',
                    'od_mm' => $num($c->od_mm), 'd1' => $num($c->d1), 'd2' => $num($c->d2), 'd3' => $num($c->d3), 'd4' => $num($c->d4),
                    'thickness_mm' => $num($c->thickness_mm), 'ib_height_mm' => $num($c->ib_height_mm), 'length_mm' => $num($c->length_mm),
                    'jig_option_id' => $opt($c->jig_option_id, $c->jig_other), 'jig_other' => $c->jig_other,
                    'visual_option_id' => $opt($c->visual_option_id, $c->visual_other), 'visual_other' => $c->visual_other,
                    'compliance_ids' => $c->compliances->pluck('id')->values(), 'compliance_other_on' => (bool) $c->compliance_other,
                    'compliance_other' => $c->compliance_other,
                    'actual_cutting' => $c->actual_cutting, 'planned_qty' => $c->planned_qty, 'problem' => $c->problem,
                    'photos' => $c->attachments->map(fn ($a) => ['id' => $a->id, 'url' => $a->url()])->values(),
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, EvaluationForm $form)
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403);
        $this->service->saveEvaluation($request, $form);

        return response()->json(['redirect' => route('forms.evaluation.show', $form), 'message' => 'Perubahan tersimpan.']);
    }

    public function destroy(Request $request, EvaluationForm $form)
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403);
        $this->service->delete($request, $form);

        return redirect()->route('forms.evaluation.index')->with('success', 'Form dihapus. Riwayatnya tetap tersimpan di log aktivitas.');
    }
}
