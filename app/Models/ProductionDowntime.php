<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionDowntime extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function reason()
    {
        return $this->belongsTo(DowntimeReason::class, 'downtime_reason_id');
    }
}
