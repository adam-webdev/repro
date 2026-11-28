<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\EvaluationCheck;
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

/** Simpan / ubah / hapus Form Rekapan (produksi) & Form Daily Supervisor (evaluasi), lengkap dengan audit trail. */
class KaruFormService
{
    public static function canEdit(User $user, Model $form): bool
    {
        return ! $form->trashed() && ($user->isMaster() || ($user->role === 'karu' && $form->user_id === $user->id));
    }

    /**
     * Info bagian atas form: akun karu pengganti (area wajib dipilih) & area awal (area tugas karu).
     * Semua karu bisa input di semua area & mesin.
     *
     * @return array{substituteAccount: bool, defaultAreaId: ?int}
     */
    public static function headerContext(User $user, ?Model $form): array
    {
        $owner = $form?->user ?? $user;

        return [
            'substituteAccount' => $owner->isSubstitute(),
            'defaultAreaId' => $form ? null : $owner->area_id,
        ];
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
            'visualPipeId' => FormOption::visualPipeId(),
            'jigSizeIds' => FormOption::jigSizeIds(),
        ];
    }

    // ------------------------------------------------------------------ Form Produksi

    public function saveProduction(Request $request, ?ProductionForm $form = null): ProductionForm
    {
        $d = $this->validateHeader($request, $form) + $request->validate([
            'items' => 'required|array|min:1|max:40',
            'items.*.machine_id' => 'required|integer|exists:machines,id',
            'items.*.machine_type' => ['required', Rule::in(array_keys(config('production.machine_types')))],
            'items.*.order_no' => 'required|string|max:20',
            'items.*.dimension' => 'required|string|max:60',
            'items.*.planned_start' => 'required|date_format:H:i',
            'items.*.planned_qty' => 'required|integer|min:0|max:10000000',
            'items.*.working_minutes' => 'required|integer|min:0|max:1440',
            'items.*.cutting_minutes' => 'required|integer|min:0|max:1440',
            'items.*.std_output' => 'required|numeric|min:0|max:100000',
            'items.*.act_output' => 'required|numeric|min:0|max:100000',
            'items.*.ok_qty' => 'required|integer|min:0|max:10000000',
            'items.*.second_grade_qty' => 'nullable|integer|min:0|max:10000000',
            'items.*.reject_reason' => 'nullable|string|max:255',
            'items.*.nc_qty' => 'nullable|integer|min:0|max:10000000',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.problems' => 'nullable|array|max:30',
            'items.*.problems.*.kind' => ['required', Rule::in(['setup', 'bongkar', 'dt'])],
            'items.*.problems.*.option_id' => 'nullable|integer|exists:form_options,id',
            'items.*.problems.*.other_text' => 'nullable|string|max:150',
            'items.*.problems.*.minutes' => 'required|integer|min:0|max:1440',
            'items.*.attachments' => 'nullable|array|max:20',
            'items.*.attachments.*' => 'integer',
        ], $this->messages(), $this->attributes());

        // aturan lanjutan: "Lainnya" wajib diketik; opsi harus sesuai jenis (Set Up / DT & jenis mesin)
        $valid = FormOption::get(['id', 'group', 'machine_type'])->keyBy('id');
        $errors = [];
        foreach ($d['items'] as $i => $it) {
            if (($it['cutting_minutes'] ?? 0) > ($it['working_minutes'] ?? PHP_INT_MAX)) {
                $errors["items.$i.cutting_minutes"] = 'Cutting time tidak boleh lebih besar dari waktu kerja.';
            } elseif (($used = self::problemMinutes($it)) > ($spare = (int) $it['working_minutes'] - (int) $it['cutting_minutes'])) {
                $errors["items.$i.problem_minutes"] = "Total menit Set Up + Bongkar + Downtime ({$used} mnt) melebihi sisa waktu kerja − cutting time ({$spare} mnt). "
                    .'Kurangi menit salah satunya.';
            }
            if (($it['second_grade_qty'] ?? 0) > 0 && blank($it['reject_reason'] ?? null)) {
                $errors["items.$i.reject_reason"] = 'Alasan reject wajib diisi.';
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
            $form->fill([
                'form_date' => $d['form_date'], 'shift' => $d['shift'], 'area_id' => $d['area_id'] ?? null,
                'by_substitute' => $d['by_substitute'], 'substitute_for_id' => $d['substitute_for_id'], 'updated_by' => $user->id,
            ]);
            $isNew = ! $form->exists;
            $form->save();

            $this->detachOld($form->items()->pluck('id'), 'production_item');
            $form->items()->delete();
            foreach (array_values($d['items']) as $i => $it) {
                $item = $form->items()->create([
                    'machine_id' => $it['machine_id'], 'machine_type' => $it['machine_type'], 'order_no' => $it['order_no'] ?? null,
                    'dimension' => $it['dimension'] ?? null,
                    'planned_start' => $it['planned_start'] ?? null, 'planned_qty' => $it['planned_qty'] ?? null,
                    'working_minutes' => $it['working_minutes'] ?? null, 'cutting_minutes' => $it['cutting_minutes'] ?? null,
                    'std_output' => $it['std_output'] ?? null, 'act_output' => $it['act_output'] ?? null,
                    'ok_qty' => $it['ok_qty'] ?? null, 'second_grade_qty' => $it['second_grade_qty'] ?? null, 'nc_qty' => $it['nc_qty'] ?? null,
                    'reject_reason' => ($it['second_grade_qty'] ?? 0) > 0 ? ($it['reject_reason'] ?? null) : null,
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
        // Form Daily Supervisor: kolom wajib diisi kecuali foto (opsional). OD lama, panjang lama & planning (pindah ke Form Rekapan)
        // tetap opsional supaya data lama tidak hilang saat diedit. Jam cek diisi otomatis saat disimpan (WIB).
        $this->normalizeDecimals($request);
        $twoDecimals = 'regex:/^-?\d+(\.\d{1,2})?$/';
        $dec = ['required', 'numeric', 'min:0', 'max:100000', $twoDecimals];
        // panjang: P1–P3 wajib, kecuali data lama yang masih memakai satu kolom panjang
        $length = ['required_without:checks.*.length_mm', 'nullable', 'numeric', 'min:0', 'max:100000', $twoDecimals];
        $d = $this->validateHeader($request, $form) + $request->validate([
            'checks' => 'required|array|min:1|max:40',
            'checks.*.id' => 'nullable|integer',
            'checks.*.machine_id' => 'required|integer|exists:machines,id',
            'checks.*.customer' => 'required|string|max:150',
            'checks.*.dimension' => 'required|string|max:60',
            'checks.*.planned_start' => 'nullable|date_format:H:i',
            'checks.*.od_mm' => 'nullable|numeric|min:0|max:100000', 'checks.*.d1' => $dec, 'checks.*.d2' => $dec, 'checks.*.d3' => $dec, 'checks.*.d4' => $dec,
            'checks.*.thickness_mm' => $dec,
            'checks.*.ib_height_mm' => ['required', 'numeric', 'min:-100000', 'max:100000', $twoDecimals], // Tinggi IB boleh minus
            'checks.*.p1' => $length, 'checks.*.p2' => $length, 'checks.*.p3' => $length,
            'checks.*.length_mm' => 'nullable|numeric|min:0|max:100000',
            'checks.*.jig_option_ids' => 'nullable|array|max:20',
            'checks.*.jig_option_ids.*' => 'integer|exists:form_options,id',
            'checks.*.jig_sizes' => 'nullable|array|max:20',
            'checks.*.jig_sizes.*' => 'nullable|string|max:40',
            'checks.*.jig_other' => 'nullable|string|max:150',
            'checks.*.visual_option_id' => 'nullable|integer|exists:form_options,id',
            'checks.*.visual_other' => 'nullable|string|max:150',
            'checks.*.compliance_option_ids' => 'nullable|array|max:20',
            'checks.*.compliance_option_ids.*' => 'integer|exists:form_options,id',
            'checks.*.compliance_other' => 'nullable|string|max:150',
            'checks.*.actual_cutting' => 'required|integer|min:0|max:10000000',
            'checks.*.planned_qty' => 'nullable|integer|min:0|max:10000000',
            'checks.*.problem' => 'required|string|max:2000',
            'checks.*.attachments' => 'nullable|array|max:20',
            'checks.*.attachments.*' => 'integer',
            'checks.*.pipe_attachments' => 'nullable|array|max:20',
            'checks.*.pipe_attachments.*' => 'integer',
            'checks.*.pipe_photo_url' => 'nullable|url|max:500',
        ], $this->messages(), $this->attributes());

        $groups = FormOption::pluck('group', 'id');
        $visualPipeId = FormOption::visualPipeId();
        $jigSizeIds = FormOption::jigSizeIds();
        $errors = [];
        foreach ($d['checks'] as $i => $c) {
            $visualId = $c['visual_option_id'] ?? null;
            if ($visualId && ($groups[$visualId] ?? null) !== 'visual') {
                $errors["checks.$i.visual_option_id"] = 'Pilihan tidak valid.';
            }
            if (! $visualId && blank($c['visual_other'] ?? null)) {
                $errors["checks.$i.visual_option_id"] = 'Visual wajib dicentang (atau isi Lainnya).';
            }

            // Jig: boleh lebih dari satu; JIG In / JIG Out wajib disertai ukuran jig
            $jigIds = array_map('intval', $c['jig_option_ids'] ?? []);
            if (! $jigIds && blank($c['jig_other'] ?? null)) {
                $errors["checks.$i.jig_option_ids"] = 'Jig wajib dicentang minimal satu (atau isi Lainnya).';
            }
            foreach ($jigIds as $id) {
                if (($groups[$id] ?? null) !== 'jig') {
                    $errors["checks.$i.jig_option_ids"] = 'Pilihan tidak valid.';
                } elseif (in_array($id, $jigSizeIds, true) && blank($c['jig_sizes'][$id] ?? null)) {
                    $errors["checks.$i.jig_sizes.$id"] = 'Ukuran jig wajib diisi.';
                }
            }

            if (empty($c['compliance_option_ids']) && blank($c['compliance_other'] ?? null)) {
                $errors["checks.$i.compliance_option_ids"] = 'Kontrol kepatuhan wajib dicentang minimal satu.';
            }
            foreach ($c['compliance_option_ids'] ?? [] as $id) {
                if (($groups[$id] ?? null) !== 'compliance') {
                    $errors["checks.$i.compliance_option_ids"] = 'Pilihan tidak valid.';
                }
            }
            $pipeChecked = $visualPipeId && in_array($visualPipeId, array_map('intval', $c['compliance_option_ids'] ?? []), true);
            if ($pipeChecked && empty($c['pipe_attachments']) && blank($c['pipe_photo_url'] ?? null)) {
                $errors["checks.$i.pipe_attachments"] = 'Pengecekan visual pipa dicentang: wajib unggah foto atau isi link gambar.';
            }
        }
        $this->ensureAttachments($request, $d['checks'], $form, $errors, 'checks');
        $pipeOn = fn (array $c) => $visualPipeId && in_array($visualPipeId, array_map('intval', $c['compliance_option_ids'] ?? []), true);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = $form?->snapshot();
        // jam cek: pengecekan lama tetap memakai jamnya, pengecekan baru = jam saat disimpan (WIB)
        $oldTimes = $form ? $form->checks()->pluck('check_time', 'id') : collect();
        $now = now()->format('H:i:s');

        return DB::transaction(function () use ($request, $form, $d, $before, $pipeOn, $jigSizeIds, $oldTimes, $now) {
            $user = $request->user();
            $form ??= new EvaluationForm(['user_id' => $user->id, 'employee_id' => $user->employee_id, 'created_by' => $user->id]);
            $form->fill([
                'form_date' => $d['form_date'], 'shift' => $d['shift'], 'area_id' => $d['area_id'] ?? null,
                'by_substitute' => $d['by_substitute'], 'substitute_for_id' => $d['substitute_for_id'], 'updated_by' => $user->id,
            ]);
            $isNew = ! $form->exists;
            $form->save();

            $this->detachOld($form->checks()->pluck('id'), 'evaluation_check');
            $form->checks()->delete();
            foreach (array_values($d['checks']) as $i => $c) {
                $customerName = Customer::normalizeName($c['customer'] ?? null);
                $check = $form->checks()->create([
                    'seq' => $i + 1,
                    'check_time' => $oldTimes->get($c['id'] ?? null) ?? $now,
                    'machine_id' => $c['machine_id'],
                    'customer_id' => $customerName ? Customer::firstOrCreate(['name' => $customerName])->id : null,
                    'dimension' => $c['dimension'] ?? null,
                    'planned_start' => $c['planned_start'] ?? null,
                    'od_mm' => $c['od_mm'] ?? null, 'd1' => $c['d1'] ?? null, 'd2' => $c['d2'] ?? null, 'd3' => $c['d3'] ?? null, 'd4' => $c['d4'] ?? null,
                    'thickness_mm' => $c['thickness_mm'] ?? null, 'ib_height_mm' => $c['ib_height_mm'] ?? null, 'length_mm' => $c['length_mm'] ?? null,
                    'p1' => $c['p1'] ?? null, 'p2' => $c['p2'] ?? null, 'p3' => $c['p3'] ?? null,
                    'jig_option_id' => null, 'jig_other' => $c['jig_other'] ?? null,
                    'visual_option_id' => $c['visual_option_id'] ?? null, 'visual_other' => empty($c['visual_option_id']) ? ($c['visual_other'] ?? null) : null,
                    'compliance_other' => $c['compliance_other'] ?? null,
                    'pipe_photo_url' => $pipeOn($c) ? ($c['pipe_photo_url'] ?? null) : null,
                    'actual_cutting' => $c['actual_cutting'] ?? null,
                    'planned_qty' => $c['planned_qty'] ?? null,
                    'problem' => $c['problem'] ?? null,
                ]);
                $check->compliances()->sync(array_unique($c['compliance_option_ids'] ?? []));
                $check->jigs()->sync(collect($c['jig_option_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->mapWithKeys(fn ($id) => [
                    $id => ['size' => in_array($id, $jigSizeIds, true) ? (trim((string) ($c['jig_sizes'][$id] ?? '')) ?: null) : null],
                ])->all());
                $this->attach($c['attachments'] ?? [], 'evaluation_check', $check->id);
                if ($pipeOn($c)) {
                    $this->attach($c['pipe_attachments'] ?? [], 'evaluation_check', $check->id, Attachment::VISUAL_PIPE);
                }
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

    /**
     * Hapus satu pengecekan (mis. salah ketik). Jika itu pengecekan terakhir di form, form ikut dihapus (soft delete).
     * Perubahan tercatat di log aktivitas; foto dilepas tapi filenya tetap disimpan.
     */
    public function deleteCheck(Request $request, EvaluationCheck $check): void
    {
        $form = $check->form;
        if ($form->checks()->count() <= 1) {
            $this->delete($request, $form);

            return;
        }

        $before = $form->snapshot();
        DB::transaction(function () use ($request, $form, $check, $before) {
            $this->detachOld(collect([$check->id]), 'evaluation_check');
            $check->delete();
            foreach ($form->checks()->get() as $i => $c) {
                $c->update(['seq' => $i + 1]);
            }
            $form->forceFill(['updated_by' => $request->user()->id])->save();
            $this->log(false, $form, $before, $form->snapshot());
        });
        SummaryBuilder::bumpCache();
    }

    // ------------------------------------------------------------------ helper

    /**
     * Tanggal, shift, area & keterangan pengganti. Semua karu bebas memilih area.
     * Akun karu pengganti wajib memilih area; karu mana pun boleh mengisi "Menggantikan" bila menggantikan karu yang tidak masuk.
     *
     * @return array{form_date: string, shift: int, area_id: ?int, by_substitute: bool, substitute_for_id: ?int}
     */
    private function validateHeader(Request $request, ?Model $form): array
    {
        $owner = $form?->user ?? $request->user();
        $substituteAccount = $owner->isSubstitute();

        $d = $request->validate([
            'form_date' => 'required|date|before_or_equal:today',
            'shift' => ['required', 'integer', Rule::in(array_keys(config('production.karu_shifts')))],
            'area_id' => [$substituteAccount ? 'required' : 'nullable', 'integer', 'exists:areas,id'],
            'substitute_for_id' => ['nullable', 'integer', Rule::notIn([$owner->id]), Rule::exists('users', 'id')->where('role', 'karu')],
        ], [
            'form_date.before_or_equal' => 'Tanggal tidak boleh melebihi hari ini.',
            'area_id.required' => 'Pilih area yang Anda gantikan.',
            'substitute_for_id.not_in' => 'Tidak bisa menggantikan diri sendiri.',
        ], $this->attributes());

        $d['substitute_for_id'] ??= null;
        $d['by_substitute'] = $substituteAccount || $d['substitute_for_id'] !== null;

        return $d;
    }

    /** Total menit Set Up + Bongkar + Downtime satu PRO. */
    private static function problemMinutes(array $item): int
    {
        return (int) collect($item['problems'] ?? [])->sum(fn ($p) => (int) ($p['minutes'] ?? 0));
    }

    /** Angka ukur boleh diketik dengan koma (mis. 28,12): ubah ke titik sebelum divalidasi. */
    private function normalizeDecimals(Request $request): void
    {
        $checks = $request->input('checks');
        if (! is_array($checks)) {
            return;
        }
        foreach ($checks as $i => $c) {
            foreach (['od_mm', 'd1', 'd2', 'd3', 'd4', 'thickness_mm', 'ib_height_mm', 'length_mm', 'p1', 'p2', 'p3'] as $k) {
                if (is_array($c) && is_string($c[$k] ?? null)) {
                    $v = str_replace(',', '.', preg_replace('/\s+/', '', $c[$k]));
                    $checks[$i][$k] = $v === '' ? null : $v;
                }
            }
        }
        $request->merge(['checks' => $checks]);
    }

    /** Foto harus milik pengguna ini (baru diunggah) atau sudah menempel di form yang sama. */
    private function ensureAttachments(Request $request, array $rows, ?Model $form, array &$errors, string $key = 'items'): void
    {
        $ids = collect($rows)->flatMap(fn ($r) => [...($r['attachments'] ?? []), ...($r['pipe_attachments'] ?? [])])->filter()->unique();
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
            Attachment::where('attachable_type', $type)->whereIn('attachable_id', $childIds)->update(['attachable_type' => null, 'attachable_id' => null, 'purpose' => null]);
        }
    }

    private function attach(array $ids, string $type, int $id, ?string $purpose = null): void
    {
        if ($ids) {
            Attachment::whereIn('id', $ids)->update(['attachable_type' => $type, 'attachable_id' => $id, 'purpose' => $purpose]);
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
            'required_without' => ':attribute wajib diisi.',
            'exists' => ':attribute tidak ditemukan.',
            'numeric' => ':attribute harus angka.',
            'integer' => ':attribute harus angka bulat.',
            'min' => ':attribute tidak boleh negatif.',
            'regex' => ':attribute maksimal 2 angka di belakang koma.',
            'date_format' => 'Format jam tidak valid.',
            'checks.*.pipe_photo_url.url' => 'Link gambar harus berupa URL lengkap, mis. https://…',
        ];
    }

    private function attributes(): array
    {
        return [
            'form_date' => 'Tanggal', 'shift' => 'Shift', 'area_id' => 'Area', 'substitute_for_id' => 'Menggantikan',
            'items.*.machine_id' => 'Mesin', 'items.*.order_no' => 'No. PRO', 'checks.*.machine_id' => 'Mesin', 'items.*.machine_type' => 'Jenis mesin',
            'items.*.working_minutes' => 'Waktu kerja', 'items.*.cutting_minutes' => 'Cutting time',
            'items.*.std_output' => 'Std output', 'items.*.act_output' => 'Act output', 'items.*.ok_qty' => 'Hasil OK',
            'items.*.second_grade_qty' => 'Reject', 'items.*.reject_reason' => 'Alasan reject', 'items.*.nc_qty' => 'Hasil NC', 'items.*.problems.*.minutes' => 'Menit',
            'items.*.problems.*.kind' => 'Jenis problem', 'items.*.dimension' => 'Dimensi',
            'items.*.planned_start' => 'Start jam planning', 'items.*.planned_qty' => 'Qty planning',
            'checks.*.check_time' => 'Jam cek', 'checks.*.customer' => 'Customer', 'checks.*.dimension' => 'Size / dimensi',
            'checks.*.planned_start' => 'Start jam planning', 'checks.*.planned_qty' => 'Qty planning',
            'checks.*.d1' => 'D1', 'checks.*.d2' => 'D2', 'checks.*.d3' => 'D3', 'checks.*.d4' => 'D4',
            'checks.*.thickness_mm' => 'Tebal', 'checks.*.ib_height_mm' => 'Tinggi IB', 'checks.*.length_mm' => 'Panjang',
            'checks.*.p1' => 'P1', 'checks.*.p2' => 'P2', 'checks.*.p3' => 'P3', 'checks.*.jig_sizes.*' => 'Ukuran jig',
            'checks.*.actual_cutting' => 'Actual cutting', 'checks.*.problem' => 'Problem / keterangan',
        ];
    }
}
