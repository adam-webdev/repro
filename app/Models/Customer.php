<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    /** "PT. RINNAI INDONESIA", " RINNAI INDONESIA" -> "RINNAI INDONESIA" */
    public static function normalizeName(?string $name): ?string
    {
        $n = strtoupper(trim((string) $name));
        $n = preg_replace('/^PT\.?\s+/', '', $n);
        $n = str_replace(['.', ','], '', $n);
        $n = preg_replace('/\s+TBK$/', '', $n);
        $n = trim(preg_replace('/\s+/', ' ', $n));

        return $n === '' || $n === '#N/A' ? null : $n;
    }
}
