<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionFormProblem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function option()
    {
        return $this->belongsTo(FormOption::class, 'form_option_id');
    }
}
