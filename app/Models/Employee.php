<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, SoftDeletes;

    protected $guarded = [];

    public const GENDERS = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

    public function user()
    {
        return $this->hasOne(User::class)->withTrashed();
    }

    public function auditLabel(): string
    {
        return trim(($this->code ? $this->code.' · ' : '').$this->name);
    }

    public function genderLabel(): ?string
    {
        return self::GENDERS[$this->gender] ?? null;
    }
}
