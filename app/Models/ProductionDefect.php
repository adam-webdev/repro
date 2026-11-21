<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionDefect extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function type()
    {
        return $this->belongsTo(DefectType::class, 'defect_type_id');
    }
}
