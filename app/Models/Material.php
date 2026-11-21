<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $guarded = [];

    protected $casts = ['od_mm' => 'float', 'thickness_mm' => 'float', 'length_mm' => 'float'];

    /**
     * Pecah kode material, contoh X1B13AIA0100+08620:
     *  - 5 digit terakhir / 10 = panjang hasil (mm)   -> terbukti cocok 869/869 material
     *  - 4 digit sebelum tanda +/- / 10 = OD (mm)      -> dugaan, perlu konfirmasi
     *  - 2 digit setelah 3 karakter awal / 10 = tebal  -> dugaan, perlu konfirmasi
     */
    public static function parseCode(string $code): array
    {
        if (preg_match('/^[A-Z0-9]{3}(\d{2})[A-Z]{3}(\d{4})[+-](\d{5})$/', $code, $m)) {
            return [
                'thickness_mm' => (int) $m[1] / 10,
                'od_mm' => (int) $m[2] / 10,
                'length_mm' => (int) $m[3] / 10,
            ];
        }

        return [];
    }

    public function getSizeLabelAttribute(): string
    {
        $parts = [];
        if ($this->od_mm) {
            $parts[] = 'Ø '.rtrim(rtrim(number_format($this->od_mm, 2, ',', '.'), '0'), ',');
        }
        if ($this->thickness_mm) {
            $parts[] = 'T '.rtrim(rtrim(number_format($this->thickness_mm, 2, ',', '.'), '0'), ',');
        }
        if ($this->length_mm) {
            $parts[] = 'L '.rtrim(rtrim(number_format($this->length_mm, 2, ',', '.'), '0'), ',').' mm';
        }

        return implode(' × ', $parts) ?: '-';
    }
}
