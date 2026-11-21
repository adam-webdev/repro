<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\EvaluationForm;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\ProductionForm;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Simpan / ubah / hapus Form Produksi & Form Evaluasi, lengkap dengan audit trail. */
class KaruFormService
{
    public static function canEdit(User $user, Model $form): bool
    {
        return ! $form->trashed() && ($user->isMaster() || ($user->role === 'karu' && $form->user_id === $user->id));
    }

    /** Opsi dropdown & master data untuk halaman form (dikirim ke Alpine). */
    public function formConfig(): array
    {
        $opts = FormOption::where('is_active', true)->orderBy('sort')->get(['id', 'group', 'machine_type', 'label']);

        return [
            'machines' => Machine::where('is_active', true)->orderBy('code')->get(['id', 'code', 'area_id'])
                ->map(fn ($m) => ['id' => $m->id, 'code' => $m->code, 'area_id' => $m->area_id, 'type' => Machine::formType($m->code)])->values(),
            'machineTypes' => collect(config('production.machine_types'))->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label']])->values(),
            'options' => $opts->groupBy('group')->map(fn ($g) => $g->map(fn ($o) => ['id' => $o->id, 'type' => $o->machine_type, 'label' => $o->label])->values()),
            'customers' => Customer::orderBy('name')->pluck('name'),
        ];
    }

    // ------------------------------------------------------------------ Form Produksi

    public function saveProduction(Request $request, ?ProductionForm $form = null): ProductionForm
    {
        $d = $this->validateHeader($request) + $request->validate([
            'items' => 'required|array|min:1|max:40',
            'items.*.machine_id' => 'required|integer|exists:machines,id',
            'items.*.machine_type' => ['required', Rule::in(array_keys(config('production.machine_types')))],
            'items.*.dimension' => 'nullable|string|max:60',
            'items.*.working_minutes' => 'nullable|integer|min:0|max:1440',
            'items.*.cutting_minutes' => 'nullable|integer|min:0|max:1440',
            'items.*.std_output' => 'nullable|numeric|min:0|max:100000',
            'items.*.act_output' => 'nullable|numeric|min:0|max:100000',
            'items.*.ok_qty' => 'nullable|integer|min:0|max:10000000',
            'items.*.second_grade_qty' => 'nullable|integer|min:0|max:10000000',
            'items.*.nc_qty' => 'nullable|integer|min:0|max:10000000',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.problems' => 'nullable|array|max:30',
            'items.*.problems.*.kind' => ['required', Rule::in(['setup', 'dt'])],
            'items.*.problems.*.option_id' => 'nullable|integer|exists:form_options,id',
            'items.*.problems.*.other_text' => 'nullable|string|max:150',
            'items.*.problems.*.minutes' => 'nullable|integer|min:0|max:1440',
            'items.*.attachments' => 'nullable|array|max:20',
            'items.*.attachments.*' => 'integer',
        ], $this->messages(), $this->attributes());

        // aturan lanjutan: "Lainnya" wajib diketik; opsi harus sesuai jenis (Set Up / DT & jenis mesin)
        $valid = FormOption::get(['id', 'group', 'machine_type'])->keyBy('id');
        $errors = [];
        foreach ($d['items'] as $i => $it) {
            if (($it['cutting_minutes'] ?? 0) > ($it['working_minutes'] ?? PHP_INT_MAX)) {
                $errors["items.$i.cutting_minutes"] = 'Cutting time tidak boleh lebih besar dari waktu kerja.';
            }
            foreach ($it['problems'] ?? [] as $j => $p) {
                $opt = isset($p['option_id']) ? $valid[$p['option_id']] ?? null : null;
                if (! $opt && blank($p['other_text'] ?? null)) {
                    $errors["items.$i.problems.$j.option_id"] = 'Pilih keterangan atau isi "Lainnya".';
                }
                if ($opt && ($opt->group !== $p['kind'] || ($opt->machine_type && $opt->machine_type !== $it['machine_type']))) {
                    $errors["items.$i.problems.$j.option_id"] = 'Keterangan tidak sesuai jenis problem / jenis mesin.';
                }
            }
        }
        $this->ensureAttachments($request, $d['items'], $form, $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = $form?->snapshot();

        return DB::transaction(function () use ($request, $form, $d, $before) {
            $user = $request->user();
            $form ??= new ProductionForm(['user_id' => $user->id, 'employee_id' => $user->employee_id, 'created_by' => $user->id]);
            $form->fill(['form_date' => $d['form_date'], 'shift' => $d['shift'], 'area_id' => $d['area_id'] ?? null, 'updated_by' => $user->id]);
            $isNew = ! $form->exists;
            $form->save();

            $this->detachOld($form->items()->pluck('id'), 'production_item');
            $form->items()->delete();
            foreach (array_values($d['items']) as $i => $it) {
                $item = $form->items()->create([
                    'machine_id' => $it['machine_id'], 'machine_type' => $it['machine_type'], 'dimension' => $it['dimension'] ?? null,
                    'working_minutes' => $it['working_minutes'] ?? null, 'cutting_minutes' => $it['cutting_minutes'] ?? null,
                    'std_output' => $it['std_output'] ?? null, 'act_output' => $it['act_output'] ?? null,
                    'ok_qty' => $it['ok_qty'] ?? null, 'second_grade_qty' => $it['second_grade_qty'] ?? null, 'nc_qty' => $it['nc_qty'] ?? null,
                    'note' => $it['note'] ?? null, 'sort' => $i,
                ]);
                foreach ($it['problems'] ?? [] as $p) {
                    $item->problems()->create([
                        'kind' => $p['kind'], 'form_option_id' => $p['option_id'] ?? null,
                        'other_text' => empty($p['option_id']) ? ($p['other_text'] ?? null) : null, 'minutes' => $p['minutes'] ?? null,
                    ]);
                }
                $this->attach($it['attachments'] ?? [], 'production_item', $item->id);
            }

            $this->log($isNew, $form, $before, $form->snapshot());
            SummaryBuilder::bumpCache();

            return $form;
        });
    }

    // ------------------------------------------------------------------ Form Evaluasi

    public function saveEvaluation(Request $request, ?EvaluationForm $form = null): EvaluationForm
    {
        $dec = 'nullable|numeric|min:0|max:100000';
        $d = $this->validateHeader($request) + $request->validate([
            'checks' => 'required|array|min:1|max:40',
            'checks.*.check_time' => 'nullable|date_format:H:i',
            'checks.*.machine_id' => 'required|integer|exists:machines,id',
            'checks.*.customer' => 'nullable|string|max:150',
            'checks.*.dimension' => 'nullable|string|max:60',
            'checks.*.od_mm' => $dec, 'checks.*.d1' => $dec, 'checks.*.d2' => $dec, 'checks.*.d3' => $dec, 'checks.*.d4' => $dec,
            'checks.*.thickness_mm' => $dec, 'checks.*.ib_height_mm' => $dec, 'checks.*.length_mm' => $dec,
            'checks.*.jig_option_id' => 'nullable|integer|exists:form_options,id',
            'checks.*.jig_other' => 'nullable|string|max:150',
            'checks.*.visual_option_id' => 'nullable|integer|exists:form_options,id',
            'checks.*.visual_other' => 'nullable|string|max:150',
            'checks.*.compliance_option_id' => 'nullable|integer|exists:form_options,id',
            'checks.*.compliance_other' => 'nullable|string|max:150',
            'checks.*.actual_cutting' => 'nullable|integer|min:0|max:10000000',
            'checks.*.problem' => 'nullable|string|max:2000',
            'checks.*.attachments' => 'nullable|array|max:20',
            'checks.*.attachments.*' => 'integer',
        ], $this->messages(), $this->attributes());

        $groups = FormOption::pluck('group', 'id');
        $errors = [];
        foreach ($d['checks'] as $i => $c) {
            foreach (['jig', 'visual', 'compliance'] as $g) {
                $id = $c[$g.'_option_id'] ?? null;
                if ($id && ($groups[$id] ?? null) !== $g) {
                    $errors["checks.$i.{$g}_option_id"] = 'Pilihan tidak valid.';
                }
            }
        }
        $this->ensureAttachments($request, $d['checks'], $form, $errors, 'checks');
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = $form?->snapshot();

        return DB::transaction(function () use ($request, $form, $d, $before) {
            $user = $request->user();
            $form ??= new EvaluationForm(['user_id' => $user->id, 'employee_id' => $user->employee_id, 'created_by' => $user->id]);
            $form->fill(['form_date' => $d['form_date'], 'shift' => $d['shift'], 'area_id' => $d['area_id'] ?? null, 'updated_by' => $user->id]);
            $isNew = ! $form->exists;
            $form->save();

            $this->detachOld($form->checks()->pluck('id'), 'evaluation_check');
            $form->checks()->delete();
            foreach (array_values($d['checks']) as $i => $c) {
                $customerName = Customer::normalizeName($c['customer'] ?? null);
                $check = $form->checks()->create([
                    'seq' => $i + 1,
                    'check_time' => $c['check_time'] ?? null,
                    'machine_id' => $c['machine_id'],
                    'customer_id' => $customerName ? Customer::firstOrCreate(['name' => $customerName])->id : null,
                    'dimension' => $c['dimension'] ?? null,
                    'od_mm' => $c['od_mm'] ?? null, 'd1' => $c['d1'] ?? null, 'd2' => $c['d2'] ?? null, 'd3' => $c['d3'] ?? null, 'd4' => $c['d4'] ?? null,
                    'thickness_mm' => $c['thickness_mm'] ?? null, 'ib_height_mm' => $c['ib_height_mm'] ?? null, 'length_mm' => $c['length_mm'] ?? null,
                    'jig_option_id' => $c['jig_option_id'] ?? null, 'jig_other' => empty($c['jig_option_id']) ? ($c['jig_other'] ?? null) : null,
                    'visual_option_id' => $c['visual_option_id'] ?? null, 'visual_other' => empty($c['visual_option_id']) ? ($c['visual_other'] ?? null) : null,
                    'compliance_option_id' => $c['compliance_option_id'] ?? null,
                    'compliance_other' => empty($c['compliance_option_id']) ? ($c['compliance_other'] ?? null) : null,
                    'actual_cutting' => $c['actual_cutting'] ?? null,
                    'problem' => $c['problem'] ?? null,
                ]);
                $this->attach($c['attachments'] ?? [], 'evaluation_check', $check->id);
            }

            $this->log($isNew, $form, $before, $form->snapshot());
            SummaryBuilder::bumpCache();

            return $form;
        });
    }

    // ------------------------------------------------------------------ hapus (soft delete)

    public function delete(Request $request, Model $form): void
    {
        DB::transaction(function () use ($request, $form) {
            $form->forceFill(['deleted_by' => $request->user()->id])->saveQuietly();
            $form->delete();
            ActivityLog::record('delete', $form, ActivityLog::summaryPairs(['Keterangan' => 'Data tetap disimpan dan ditandai terhapus', 'Isi terakhir' => count($form->snapshot()).' kolom']));
        });
        SummaryBuilder::bumpCache();
    }

    // ------------------------------------------------------------------ helper

    private function validateHeader(Request $request): array
    {
        return $request->validate([
            'form_date' => 'required|date|before_or_equal:today',
            'shift' => 'required|integer|in:1,2,3',
            'area_id' => 'nullable|integer|exists:areas,id',
        ], ['form_date.before_or_equal' => 'Tanggal tidak boleh melebihi hari ini.'], $this->attributes());
    }

    /** Foto harus milik pengguna ini (baru diunggah) atau sudah menempel di form yang sama. */
    private function ensureAttachments(Request $request, array $rows, ?Model $form, array &$errors, string $key = 'items'): void
    {
        $ids = collect($rows)->pluck('attachments')->flatten()->filter()->unique();
        if ($ids->isEmpty()) {
            return;
        }
        $type = $key === 'items' ? 'production_item' : 'evaluation_check';
        $ownChildIds = $form ? ($key === 'items' ? $form->items()->pluck('id') : $form->checks()->pluck('id')) : collect();
        $ok = Attachment::whereIn('id', $ids)->where(function ($q) use ($request, $type, $ownChildIds) {
            $q->where(fn ($w) => $w->whereNull('attachable_id')->where('user_id', $request->user()->id))
                ->orWhere(fn ($w) => $w->where('attachable_type', $type)->whereIn('attachable_id', $ownChildIds));
        })->count();
        if ($ok !== $ids->count()) {
            $errors[$key] = 'Sebagian foto tidak valid. Muat ulang halaman lalu unggah ulang fotonya.';
        }
    }

    /** Foto lama dilepas dulu (file tetap disimpan), lalu yang masih dipakai ditempel ke baris baru. */
    private function detachOld($childIds, string $type): void
    {
        if ($childIds->isNotEmpty()) {
            Attachment::where('attachable_type', $type)->whereIn('attachable_id', $childIds)->update(['attachable_type' => null, 'attachable_id' => null]);
        }
    }

    private function attach(array $ids, string $type, int $id): void
    {
        if ($ids) {
            Attachment::whereIn('id', $ids)->update(['attachable_type' => $type, 'attachable_id' => $id]);
        }
    }

    private function log(bool $isNew, Model $form, ?array $before, array $after): void
    {
        if ($isNew) {
            ActivityLog::record('create', $form, ['new' => $after]);

            return;
        }
        $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after)));
        $old = $new = [];
        foreach ($keys as $k) {
            $o = $before[$k] ?? null;
            $n = $after[$k] ?? null;
            if ((string) $o !== (string) $n) {
                $old[$k] = $o;
                $new[$k] = $n;
            }
        }
        if ($new) {
            ActivityLog::record('update', $form, ['old' => $old, 'new' => $new]);
        }
    }

    private function messages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu mesin.',
            'checks.required' => 'Tambahkan minimal satu pengecekan.',
            'required' => ':attribute wajib diisi.',
            'exists' => ':attribute tidak ditemukan.',
            'numeric' => ':attribute harus angka.',
            'integer' => ':attribute harus angka bulat.',
            'min' => ':attribute tidak boleh negatif.',
            'date_format' => 'Format jam tidak valid.',
        ];
    }

    private function attributes(): array
    {
        return [
            'form_date' => 'Tanggal', 'shift' => 'Shift', 'area_id' => 'Area',
            'items.*.machine_id' => 'Mesin', 'checks.*.machine_id' => 'Mesin', 'items.*.machine_type' => 'Jenis mesin',
            'items.*.working_minutes' => 'Waktu kerja', 'items.*.cutting_minutes' => 'Cutting time',
            'items.*.std_output' => 'Std output', 'items.*.act_output' => 'Act output', 'items.*.ok_qty' => 'Hasil OK',
            'items.*.second_grade_qty' => 'Hasil 2nd grade', 'items.*.nc_qty' => 'Hasil NC', 'items.*.problems.*.minutes' => 'Menit',
            'items.*.problems.*.kind' => 'Jenis problem',
        ];
    }
}
