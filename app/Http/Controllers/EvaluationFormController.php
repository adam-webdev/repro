<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\EvaluationCheck;
use App\Models\EvaluationForm;
use App\Models\User;
use App\Services\Export\EvaluationSheetExport;
use App\Services\KaruFormService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Form Daily Supervisor (dulu Form Evaluasi, PDR/PP/13).
 * Alur lihat: daftar tanggal → kartu area → detail gabungan per area (semua karu, urut jam cek).
 */
class EvaluationFormController extends Controller
{
    /** Kunci URL untuk form yang diisi tanpa memilih area. */
    public const NO_AREA = 'tanpa-area';

    public function __construct(private KaruFormService $service) {}

    /** Daftar tanggal yang punya pengecekan. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $to = $request->input('to') ?: now()->toDateString();
        $from = $request->input('from') ?: now()->subDays(29)->toDateString();

        $days = DB::table('evaluation_forms as f')
            ->join('evaluation_checks as c', 'c.evaluation_form_id', '=', 'f.id')
            ->whereNull('f.deleted_at')
            ->whereBetween('f.form_date', [$from, $to])
            ->when($request->filled('shift'), fn ($q) => $q->where('f.shift', $request->integer('shift')))
            ->when($request->filled('area_id'), fn ($q) => $q->where('f.area_id', $request->integer('area_id')))
            ->when($request->filled('user_id') && $user->role !== 'karu', fn ($q) => $q->where('f.user_id', $request->integer('user_id')))
            ->selectRaw('f.form_date, COUNT(c.id) checks, COUNT(DISTINCT f.user_id) karus, COUNT(DISTINCT COALESCE(f.area_id, 0)) areas, COUNT(DISTINCT f.shift) shifts')
            ->groupBy('f.form_date')->orderByDesc('f.form_date')
            ->paginate(31)->withQueryString();

        return view('forms.evaluation.index', [
            'days' => $days, 'from' => $from, 'to' => $to,
            'areas' => Area::orderBy('name')->get(['id', 'name']),
            'karus' => User::where('role', 'karu')->orderBy('name')->get(['id', 'name']),
            'canCreate' => $user->hasRole('karu'),
        ]);
    }

    /** Kartu area untuk satu tanggal. */
    public function day(Request $request, string $date): View
    {
        $date = $this->parseDate($date);
        $rows = DB::table('evaluation_forms as f')
            ->join('evaluation_checks as c', 'c.evaluation_form_id', '=', 'f.id')
            ->whereNull('f.deleted_at')->whereDate('f.form_date', $date)
            ->selectRaw('f.area_id, f.shift, COUNT(c.id) checks')
            ->groupBy('f.area_id', 'f.shift')->get();
        $stats = $rows->groupBy(fn ($r) => $r->area_id ?? 0)->map(fn (Collection $g) => (object) [
            'checks' => (int) $g->sum('checks'),
            'shifts' => $g->pluck('shift')->unique()->sort()->values()->all(),
        ]);

        $cards = Area::orderBy('name')->get(['id', 'name'])
            ->map(fn (Area $a) => ['key' => (string) $a->id, 'name' => $a->name, 'stat' => $stats->get($a->id)]);
        if ($stats->has(0)) {
            $cards->push(['key' => self::NO_AREA, 'name' => 'Tanpa area', 'stat' => $stats->get(0)]);
        }

        return view('forms.evaluation.day', [
            'date' => Carbon::parse($date),
            'cards' => $cards,
            'canCreate' => $request->user()->hasRole('karu'),
        ]);
    }

    /** Detail gabungan satu area pada satu tanggal: semua karu, urut shift lalu jam cek. */
    public function area(Request $request, string $date, string $area): View
    {
        $date = $this->parseDate($date);
        $areaModel = $this->resolveArea($area);
        $checks = $this->areaChecks($date, $areaModel);

        return view('forms.evaluation.area', [
            'date' => Carbon::parse($date),
            'area' => $areaModel,
            'areaKey' => $area,
            'checks' => $checks,
            'forms' => $checks->pluck('form')->unique('id')->sortBy([['shift', 'asc'], ['id', 'asc']])->values(),
            'canCreate' => $request->user()->hasRole('karu'),
        ]);
    }

    /** Export satu tanggal + area (gabungan semua karu) ke template PDR/PP/13, link foto di kolom keterangan. */
    public function areaExport(string $date, string $area, EvaluationSheetExport $export): StreamedResponse
    {
        $date = $this->parseDate($date);
        $areaModel = $this->resolveArea($area);
        $checks = $this->areaChecks($date, $areaModel);
        abort_if($checks->isEmpty(), 404, 'Belum ada pengecekan di area ini.');

        $book = $export->buildCombined(Carbon::parse($date), $areaModel?->name ?? 'Tanpa area', $checks);

        return $this->download($book, $export->combinedFilename(Carbon::parse($date), $areaModel?->name ?? 'Tanpa-area'));
    }

    public function create(Request $request): View
    {
        return view('forms.evaluation.form', [
            'form' => null, 'config' => $this->service->formConfig(), 'payload' => null,
            'defaults' => [
                'form_date' => rescue(fn () => $request->filled('date') ? $this->parseDate($request->string('date')) : null, null, false),
                'area_id' => Area::whereKey($request->integer('area_id'))->value('id'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $form = $this->service->saveEvaluation($request);

        return response()->json(['redirect' => $this->areaUrl($form), 'message' => 'Form daily supervisor tersimpan.']);
    }

    public function show(Request $request, EvaluationForm $form): View
    {
        $u = $request->user();
        abort_if($u->role === 'karu' && $form->user_id !== $u->id, 403, 'Anda hanya bisa melihat form milik Anda sendiri.');
        abort_if($form->trashed() && ! $u->isMaster(), 404);
        $form->load(['user.employee', 'area', 'creator', 'updater', 'checks.machine', 'checks.customer', 'checks.jigs', 'checks.visual', 'checks.compliances', 'checks.attachments', 'checks.pipePhotos']);

        return view('forms.evaluation.show', [
            'form' => $form,
            'canEdit' => KaruFormService::canEdit($u, $form),
            'logs' => ActivityLog::where('subject_type', 'evaluation_form')->where('subject_id', $form->id)->latest('id')->get(),
            'backUrl' => $form->trashed() ? route('forms.evaluation.index') : $this->areaUrl($form),
        ]);
    }

    /** Unduh form dalam template kertas PDR/PP/13 yang sudah terisi (.xlsx). */
    public function export(Request $request, EvaluationForm $form, EvaluationSheetExport $export): StreamedResponse
    {
        $u = $request->user();
        abort_if($u->role === 'karu' && $form->user_id !== $u->id, 403, 'Anda hanya bisa mengunduh form milik Anda sendiri.');
        abort_if($form->trashed() && ! $u->isMaster(), 404);

        return $this->download($export->build($form), $export->filename($form));
    }

    public function edit(Request $request, EvaluationForm $form): View
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403, 'Hanya Karu pembuat form atau Admin Master yang bisa mengubah form ini.');
        $form->load(['checks.customer', 'checks.compliances', 'checks.jigs', 'checks.attachments', 'checks.pipePhotos', 'user']);
        $opt = fn ($id, $other) => $id ?? ($other ? 'other' : '');
        $num = fn ($v) => $v !== null ? (float) $v : null;

        return view('forms.evaluation.form', [
            'form' => $form,
            'config' => $this->service->formConfig(),
            'defaults' => null,
            'payload' => [
                'form_date' => $form->form_date->toDateString(),
                'shift' => $form->shift,
                'area_id' => $form->area_id,
                'substitute_for_id' => $form->substitute_for_id,
                'checks' => $form->checks->map(fn ($c) => [
                    'id' => $c->id, 'check_time' => $c->check_time ? substr($c->check_time, 0, 5) : '',
                    'machine_id' => $c->machine_id, 'customer' => $c->customer?->name, 'dimension' => $c->dimension,
                    // planning lama (sekarang di Form Rekapan) ikut dikirim ulang supaya tidak hilang saat diedit
                    'planned_start' => $c->planned_start ? substr($c->planned_start, 0, 5) : null, 'planned_qty' => $c->planned_qty,
                    'od_mm' => $num($c->od_mm), 'd1' => $num($c->d1), 'd2' => $num($c->d2), 'd3' => $num($c->d3), 'd4' => $num($c->d4),
                    'thickness_mm' => $num($c->thickness_mm), 'ib_height_mm' => $num($c->ib_height_mm), 'length_mm' => $num($c->length_mm),
                    'p1' => $num($c->p1), 'p2' => $num($c->p2), 'p3' => $num($c->p3),
                    'jig_ids' => $c->jigs->pluck('id')->values(), 'jig_sizes' => (object) $c->jigs->mapWithKeys(fn ($o) => [$o->id => $o->pivot->size ?? ''])->all(),
                    'jig_other_on' => (bool) $c->jig_other, 'jig_other' => $c->jig_other,
                    'visual_option_id' => $opt($c->visual_option_id, $c->visual_other), 'visual_other' => $c->visual_other,
                    'compliance_ids' => $c->compliances->pluck('id')->values(), 'compliance_other_on' => (bool) $c->compliance_other,
                    'compliance_other' => $c->compliance_other,
                    'actual_cutting' => $c->actual_cutting, 'problem' => $c->problem,
                    'photos' => $c->attachments->map(fn ($a) => ['id' => $a->id, 'url' => $a->url()])->values(),
                    'pipe_photos' => $c->pipePhotos->map(fn ($a) => ['id' => $a->id, 'url' => $a->url()])->values(),
                    'pipe_photo_url' => $c->pipe_photo_url,
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, EvaluationForm $form): JsonResponse
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403);
        $this->service->saveEvaluation($request, $form);

        return response()->json(['redirect' => $this->areaUrl($form->fresh()), 'message' => 'Perubahan tersimpan.']);
    }

    public function destroy(Request $request, EvaluationForm $form): RedirectResponse
    {
        abort_unless(KaruFormService::canEdit($request->user(), $form), 403);
        $back = $this->areaUrl($form);
        $this->service->delete($request, $form);

        return redirect($back)->with('success', 'Form dihapus. Riwayatnya tetap tersimpan di log aktivitas.');
    }

    /** Hapus satu pengecekan milik sendiri (mis. salah ketik), kembali ke lembar area. */
    public function destroyCheck(Request $request, EvaluationCheck $check): RedirectResponse
    {
        $form = $check->form;
        abort_unless($form && KaruFormService::canEdit($request->user(), $form), 403, 'Anda hanya bisa menghapus pengecekan yang Anda input sendiri.');
        $back = $this->areaUrl($form);
        $this->service->deleteCheck($request, $check);

        return redirect($back)->with('success', 'Pengecekan dihapus. Riwayatnya tetap tersimpan di log aktivitas.');
    }

    // ------------------------------------------------------------------ helper

    /** Halaman gabungan tanggal + area tempat form ini tampil. */
    private function areaUrl(EvaluationForm $form): string
    {
        return route('forms.evaluation.area', [$form->form_date->toDateString(), $form->area_id ?? self::NO_AREA]);
    }

    private function parseDate(string $date): string
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date), 404);

        return Carbon::parse($date)->toDateString();
    }

    private function resolveArea(string $area): ?Area
    {
        return $area === self::NO_AREA ? null : Area::findOrFail((int) $area);
    }

    /**
     * Semua pengecekan pada tanggal + area (form yang tidak dihapus), dari semua karu.
     * Urut shift, lalu jam cek; jam lewat tengah malam pada shift malam ditaruh setelah jam sebelum tengah malam.
     *
     * @return Collection<int, EvaluationCheck>
     */
    private function areaChecks(string $date, ?Area $area): Collection
    {
        $shiftStart = collect(config('production.karu_shifts'))->map(fn ($s) => self::minutes($s['start']));

        return EvaluationCheck::query()
            ->whereHas('form', fn ($q) => $q->whereDate('form_date', $date)->where('area_id', $area?->id))
            ->with(['form.user.employee', 'form.substituteFor', 'machine', 'customer', 'jigs', 'visual', 'compliances', 'attachments', 'pipePhotos'])
            ->get()
            ->sortBy(function (EvaluationCheck $c) use ($shiftStart) {
                $time = $c->check_time ? self::minutes($c->check_time) : 0;
                if ($time < ($shiftStart[$c->form->shift] ?? 0)) {
                    $time += 1440;
                }

                return sprintf('%d-%05d-%08d-%04d', $c->form->shift, $time, $c->evaluation_form_id, $c->seq);
            })
            ->values();
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function download(Spreadsheet $book, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($book) {
            IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
