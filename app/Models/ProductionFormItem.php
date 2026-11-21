<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionFormItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function form()
    {
        return $this->belongsTo(ProductionForm::class, 'production_form_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function problems()
    {
        return $this->hasMany(ProductionFormProblem::class);
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
