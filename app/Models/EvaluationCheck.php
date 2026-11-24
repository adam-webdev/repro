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

    /** Kontrol kepatuhan: boleh lebih dari satu centang. */
    public function compliances()
    {
        return $this->belongsToMany(FormOption::class, 'evaluation_check_compliances', 'evaluation_check_id', 'form_option_id')->orderBy('sort');
    }

    /** Teks kepatuhan untuk tampilan / log / export, mis. "Penggunaan APD, 5R, Lainnya: ...". */
    public function complianceText(): ?string
    {
        $labels = $this->compliances->pluck('label');
        if ($this->compliance_other) {
            $labels->push('Lainnya: '.$this->compliance_other);
        }

        return $labels->isNotEmpty() ? $labels->implode(', ') : null;
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
