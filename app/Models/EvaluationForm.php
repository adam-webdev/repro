<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Form Evaluasi (Daily Supervisory Control, PDR/PP/13): pengecekan #1, #2, … beserta foto bukti. */
class EvaluationForm extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['form_date' => 'date'];

    public function checks()
    {
        return $this->hasMany(EvaluationCheck::class)->orderBy('seq');
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function auditType(): string
    {
        return 'evaluation_form';
    }

    public function auditLabel(): string
    {
        return 'Form Evaluasi '.$this->form_date->format('d/m/Y').' · Shift '.$this->shift.' · '.$this->user?->name;
    }

    public function snapshot(): array
    {
        $this->load(['area', 'checks.machine', 'checks.customer', 'checks.jig', 'checks.visual', 'checks.compliance', 'checks.attachments']);
        $out = [
            'Tanggal' => $this->form_date->format('d/m/Y'),
            'Shift' => $this->shift,
            'Area' => $this->area?->name,
        ];
        foreach ($this->checks as $c) {
            $p = 'Pengecekan #'.$c->seq.' ';
            $out += [
                $p.'jam' => $c->check_time ? substr($c->check_time, 0, 5) : null,
                $p.'mesin' => $c->machine?->code,
                $p.'customer' => $c->customer?->name,
                $p.'dimensi' => $c->dimension,
                $p.'OD' => $c->od_mm !== null ? (float) $c->od_mm : null,
                $p.'D1' => $c->d1 !== null ? (float) $c->d1 : null,
                $p.'D2' => $c->d2 !== null ? (float) $c->d2 : null,
                $p.'D3' => $c->d3 !== null ? (float) $c->d3 : null,
                $p.'D4' => $c->d4 !== null ? (float) $c->d4 : null,
                $p.'tebal' => $c->thickness_mm !== null ? (float) $c->thickness_mm : null,
                $p.'tinggi IB' => $c->ib_height_mm !== null ? (float) $c->ib_height_mm : null,
                $p.'panjang' => $c->length_mm !== null ? (float) $c->length_mm : null,
                $p.'JIG' => FormOption::display($c->jig, $c->jig_other),
                $p.'visual' => FormOption::display($c->visual, $c->visual_other),
                $p.'kepatuhan' => FormOption::display($c->compliance, $c->compliance_other),
                $p.'actual cutting' => $c->actual_cutting,
                $p.'problem' => $c->problem,
                $p.'foto' => $c->attachments->count(),
            ];
        }

        return $out;
    }
}
