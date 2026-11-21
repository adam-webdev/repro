<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DowntimeReason extends Model
{
    protected $guarded = [];

    public function category()
    {
        return $this->belongsTo(DowntimeCategory::class, 'downtime_category_id');
    }
}
