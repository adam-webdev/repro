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

    /** Evidence foto umum (opsional). */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable')->whereNull('purpose');
    }

    /** Foto pengecekan visual pipa (wajib bila kontrol kepatuhan "Pengecekan visual pipa" dicentang). */
    public function pipePhotos()
    {
        return $this->morphMany(Attachment::class, 'attachable')->where('purpose', Attachment::VISUAL_PIPE);
    }

    /** Semua foto (umum + visual pipa), untuk melepas foto lama saat form disimpan ulang. */
    public function allAttachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Link foto untuk export Excel: "Foto: url1, url2". */
    public function photoLinks(): array
    {
        return $this->attachments->map->url()->all();
    }

    /** Link bukti visual pipa: foto unggahan + link gambar yang diketik. */
    public function pipePhotoLinks(): array
    {
        return array_values(array_filter([...$this->pipePhotos->map->url()->all(), $this->pipe_photo_url]));
    }
}
