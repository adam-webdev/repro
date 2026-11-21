<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DowntimeCategory extends Model
{
    protected $guarded = [];

    public function reasons()
    {
        return $this->hasMany(DowntimeReason::class);
    }
}
