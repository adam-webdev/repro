<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/**
 * Mencatat otomatis setiap tambah / edit / hapus / pulihkan ke activity_logs.
 * Field yang tidak perlu dicatat (token, stempel waktu) disaring, password disamarkan.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => ActivityLog::record('create', $m, ['new' => $m->auditValues($m->getAttributes())]));
        static::updated(function ($m) {
            $changes = $m->auditDiff();
            if ($changes) {
                ActivityLog::record('update', $m, $changes);
            }
        });
        static::deleted(fn ($m) => ActivityLog::record('delete', $m));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($m) => ActivityLog::record('restore', $m));
        }
    }

    public function auditLabel(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }

    public function auditType(): string
    {
        return strtolower(class_basename($this));
    }

    protected function auditIgnored(): array
    {
        return ['id', 'updated_at', 'created_at', 'deleted_at', 'remember_token', 'last_login_at', 'email_verified_at'];
    }

    public function auditValues(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if (in_array($k, $this->auditIgnored(), true)) {
                continue;
            }
            $out[$k] = $k === 'password' ? '(diubah)' : $v;
        }

        return $out;
    }

    protected function auditDiff(): ?array
    {
        $new = $this->auditValues($this->getChanges());
        if (! $new) {
            return null;
        }
        $old = [];
        foreach (array_keys($new) as $k) {
            $old[$k] = $k === 'password' ? '' : $this->getOriginal($k);
        }

        return ['old' => $old, 'new' => $new];
    }
}
