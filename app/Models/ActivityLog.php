<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    public const ACTIONS = ['create' => 'Tambah', 'update' => 'Edit', 'delete' => 'Hapus', 'restore' => 'Pulihkan'];

    public const TYPES = [
        'employee' => 'Karyawan',
        'user' => 'Akun pengguna',
        'production_form' => 'Form Produksi',
        'evaluation_form' => 'Form Evaluasi',
        'production_data' => 'Data Produksi (import)',
        'sap_data' => 'Data SAP (import)',
    ];

    /**
     * Catat satu aksi. $subject = model, atau array [type, id, label, key].
     */
    public static function record(string $action, Model|array $subject, ?array $changes = null, ?User $by = null): self
    {
        $by ??= auth()->user();
        $by?->loadMissing('employee');

        if ($subject instanceof Model) {
            $type = method_exists($subject, 'auditType') ? $subject->auditType() : strtolower(class_basename($subject));
            $id = $subject->getKey();
            $label = method_exists($subject, 'auditLabel') ? $subject->auditLabel() : null;
            $key = null;
        } else {
            [$type, $id, $label, $key] = array_pad($subject, 4, null);
        }

        return static::create([
            'user_id' => $by?->id,
            'employee_id' => $by?->employee_id,
            'user_name' => $by?->name ?? 'Sistem (terminal)',
            'user_role' => $by?->role,
            'user_position' => $by?->employee?->position,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_key' => $key,
            'subject_label' => $label ? mb_substr($label, 0, 191) : null,
            'changes' => $changes,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst($this->action);
    }

    public function actionChip(): string
    {
        return match ($this->action) {
            'create' => 'chip-ok', 'delete' => 'chip-crit', 'update' => 'chip-warn', default => 'chip-muted',
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->subject_type] ?? $this->subject_type;
    }

    public function roleLabel(): string
    {
        return User::ROLES[$this->user_role] ?? ($this->user_role ?? 'Sistem');
    }

    /**
     * Ringkasan disimpan sebagai daftar [label, nilai] supaya urutannya tetap
     * (MySQL mengurutkan ulang key JSON). Dibaca kembali sebagai array label => nilai.
     */
    public static function summaryPairs(array $assoc): array
    {
        return ['summary' => collect($assoc)->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => [$k, $v])->values()->all()];
    }

    public function summary(): array
    {
        $s = $this->getAttribute('changes')['summary'] ?? [];

        return array_is_list($s) ? collect($s)->mapWithKeys(fn ($p) => [$p[0] => $p[1]])->all() : $s;
    }

    /** URL ke data yang dicatat (jika masih bisa dibuka). */
    public function subjectUrl(): ?string
    {
        return match ($this->subject_type) {
            'production_form' => $this->subject_id ? route('forms.production.show', $this->subject_id) : null,
            'evaluation_form' => $this->subject_id ? route('forms.evaluation.show', $this->subject_id) : null,
            'employee' => $this->subject_id ? route('employees.edit', $this->subject_id) : null,
            'production_data' => $this->subject_key ? route('reports.index', [
                'from' => strtok($this->subject_key, '|'), 'to' => strtok($this->subject_key, '|'),
                'shift' => substr(strrchr($this->subject_key, '|'), 1),
            ]) : null,
            default => null,
        };
    }

    /** Baris perubahan siap tampil: [[field, lama|null, baru], ...] */
    public function changeRows(): array
    {
        // catatan: $this->changes = properti internal Eloquent, jadi kolom dibaca lewat getAttribute()
        $c = $this->getAttribute('changes') ?? [];
        if (isset($c['summary'])) {
            return collect($this->summary())->map(fn ($v, $k) => [$k, null, self::show($v)])->values()->all();
        }
        $new = $c['new'] ?? [];
        $old = $c['old'] ?? [];
        $rows = [];
        foreach ($new as $k => $v) {
            $rows[] = [self::fieldLabel($k), array_key_exists($k, $old) ? self::show($old[$k], $k) : null, self::show($v, $k)];
        }

        return $rows;
    }

    private static function show($v, ?string $field = null): string
    {
        if ($v === null || $v === '') {
            return '–';
        }
        if ($field === 'role') {
            return User::ROLES[$v] ?? (string) $v;
        }
        if ($field === 'is_active' || $field === 'must_change_password' || is_bool($v)) {
            return $v ? 'Ya' : 'Tidak';
        }

        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
    }

    public static function fieldLabel(string $k): string
    {
        return [
            'name' => 'Nama', 'code' => 'Kode', 'id_card' => 'ID Card', 'gender' => 'Jenis kelamin', 'position' => 'Jabatan',
            'email' => 'Email', 'username' => 'Username', 'role' => 'Role', 'is_active' => 'Aktif', 'password' => 'Password',
            'must_change_password' => 'Wajib ganti password', 'employee_id' => 'ID karyawan', 'shift' => 'Shift',
        ][$k] ?? $k;
    }
}
