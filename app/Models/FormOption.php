<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormOption extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public const GROUPS = [
        'setup' => 'Set Up', 'dt' => 'Downtime', 'compliance' => 'Kontrol Kepatuhan', 'visual' => 'Visual', 'jig' => 'JIG',
    ];

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
