<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormOption extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public const GROUPS = [
        'setup' => 'Set Up', 'bongkar' => 'Bongkar', 'dt' => 'Downtime', 'compliance' => 'Kontrol Kepatuhan', 'visual' => 'Visual', 'jig' => 'JIG',
    ];

    /** Kontrol kepatuhan yang wajib disertai foto / link gambar pipa. */
    public const VISUAL_PIPE = 'Pengecekan visual pipa';

    /** Id opsi kontrol kepatuhan "Pengecekan visual pipa" (null jika belum ada). */
    public static function visualPipeId(): ?int
    {
        return static::where('group', 'compliance')->where('label', self::VISUAL_PIPE)->value('id');
    }

    public function downtimeReason()
    {
        return $this->belongsTo(DowntimeReason::class);
    }

    /** Label pilihan atau teks manual "Lainnya". */
    public static function display(?self $option, ?string $other): ?string
    {
        if ($option) {
            return $option->label;
        }

        return $other ? 'Lainnya: '.$other : null;
    }
}
