<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationCheck extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function form()
    {
        return $this->belongsTo(EvaluationForm::class, 'evaluation_form_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function jig()
    {
        return $this->belongsTo(FormOption::class, 'jig_option_id');
    }

    public function visual()
    {
        return $this->belongsTo(FormOption::class, 'visual_option_id');
    }

    public function compliance()
    {
        return $this->belongsTo(FormOption::class, 'compliance_option_id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
