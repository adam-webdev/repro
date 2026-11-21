<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    protected $guarded = [];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    /** Jenis mesin untuk form karu: laser | exs | umum (dari awalan kode). */
    public static function formType(string $code): string
    {
        $prefix = strtoupper(strtok($code, '-'));
        foreach (config('production.machine_types') as $key => $def) {
            if (in_array($prefix, $def['prefixes'], true)) {
                return $key;
            }
        }

        return 'umum';
    }
}
