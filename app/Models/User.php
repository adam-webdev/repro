<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, SoftDeletes;

    public const ROLES = [
        'master' => 'Admin Master',
        'karu' => 'Karu (Kepala Regu)',
        'produksi' => 'Admin Produksi',
        'viewer' => 'Viewer',
    ];

    protected $fillable = ['name', 'username', 'email', 'password', 'role', 'area_id', 'is_substitute', 'employee_id', 'is_active', 'must_change_password', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_substitute' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** Area tugas utama karu: hanya info & isian awal area di form; karu tetap bisa input di semua area. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function isSubstitute(): bool
    {
        return $this->role === 'karu' && $this->is_substitute;
    }

    /** Karu tetap yang bisa dipilih di isian "Menggantikan" (selain karu yang sedang mengisi). */
    public static function replaceableKarus(?int $exceptUserId = null): Collection
    {
        return self::where('role', 'karu')->where('is_substitute', false)
            ->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->orderBy('name')->get(['id', 'name', 'area_id']);
    }

    /** Admin Master selalu lolos. */
    public function hasRole(string ...$roles): bool
    {
        return $this->role === 'master' || in_array($this->role, $roles, true);
    }

    public function isMaster(): bool
    {
        return $this->role === 'master';
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? (string) $this->role;
    }

    public function homeRoute(): string
    {
        return $this->role === 'karu' ? 'forms.production.index' : 'dashboard';
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->username.')';
    }
}
