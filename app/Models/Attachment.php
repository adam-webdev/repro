<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto bukti. File di storage/app/private/evidence, hanya bisa dibuka lewat route yang dicek login. */
class Attachment extends Model
{
    protected $guarded = [];

    /** attachments.purpose untuk foto pengecekan visual pipa (Form Daily Supervisor). */
    public const VISUAL_PIPE = 'visual_pipe';

    public function attachable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function url(): string
    {
        return route('attachments.show', $this);
    }
}
