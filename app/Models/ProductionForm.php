<?php

namespace App\Models;

use App\Models\Concerns\HasSubstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Form Rekapan (dulu Form Produksi) yang diisi Karu: satu form = satu tanggal+shift, banyak PRO / mesin, termasuk planning. */
class ProductionForm extends Model
{
    use HasSubstitute, SoftDeletes;

    protected $guarded = [];

    protected $casts = ['form_date' => 'date'];

    public function items()
    {
        return $this->hasMany(ProductionFormItem::class)->orderBy('sort');
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
        return 'production_form';
    }

    public function auditLabel(): string
    {
        return 'Form Rekapan '.$this->form_date->format('d/m/Y').' · Shift '.$this->shift.' · '.$this->user?->name;
    }

    /** Isi form dalam bentuk datar untuk membandingkan sebelum / sesudah edit. */
    public function snapshot(): array
    {
        $this->load(['area', 'items.machine', 'items.problems.option', 'items.attachments']);
        $out = [
            'Tanggal' => $this->form_date->format('d/m/Y'),
            'Shift' => $this->shift,
            'Area' => $this->area?->name,
        ] + $this->substituteSnapshot();
        foreach ($this->items as $i => $it) {
            $p = 'Mesin #'.($i + 1).' ';
            $out += [
                $p.'mesin' => $it->machine?->code,
                $p.'no. PRO' => $it->order_no,
                $p.'dimensi' => $it->dimension,
                $p.'start planning' => $it->planned_start ? substr($it->planned_start, 0, 5) : null,
                $p.'qty planning' => $it->planned_qty,
                $p.'waktu kerja' => $it->working_minutes,
                $p.'cutting time' => $it->cutting_minutes,
                $p.'std output' => $it->std_output !== null ? (float) $it->std_output : null,
                $p.'act output' => $it->act_output !== null ? (float) $it->act_output : null,
                $p.'OK' => $it->ok_qty,
                $p.'reject' => $it->second_grade_qty,
                $p.'NC' => $it->nc_qty,
                $p.'keterangan' => $it->note,
                $p.'foto' => $it->attachments->count(),
            ];
            foreach ($it->problems as $j => $pr) {
                $out[$p.'problem '.($j + 1)] = (['setup' => 'Set Up', 'bongkar' => 'Bongkar'][$pr->kind] ?? 'DT').': '
                    .FormOption::display($pr->option, $pr->other_text).' · '.($pr->minutes ?? 0).' mnt';
            }
        }

        return $out;
    }
}
