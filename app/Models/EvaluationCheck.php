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

    /** Jig lama (satu pilihan). Data baru memakai jigs(). */
    public function jig()
    {
        return $this->belongsTo(FormOption::class, 'jig_option_id');
    }

    /** Jig yang dicentang (boleh lebih dari satu); JIG In / JIG Out membawa ukuran jig di pivot. */
    public function jigs()
    {
        return $this->belongsToMany(FormOption::class, 'evaluation_check_jigs', 'evaluation_check_id', 'form_option_id')
            ->withPivot('size')->orderBy('sort');
    }

    /** Teks jig untuk tampilan / log / export, mis. "JIG In (Ø12), JIG Panjang, Lainnya: ...". */
    public function jigText(): ?string
    {
        $labels = $this->jigs->map(fn (FormOption $o) => $o->label.($o->pivot->size ? ' ('.$o->pivot->size.')' : ''));
        if ($this->jig_other) {
            $labels->push('Lainnya: '.$this->jig_other);
        }

        return $labels->isNotEmpty() ? $labels->implode(', ') : null;
    }

    /** Panjang hasil ukur: P1 / P2 / P3 (data lama: satu kolom panjang). */
    public function lengthText(): ?string
    {
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        $values = collect([$this->p1, $this->p2, $this->p3])->filter(fn ($v) => $v !== null)->map($fmt);
        if ($values->isEmpty() && $this->length_mm !== null) {
            return $fmt($this->length_mm);
        }

        return $values->isNotEmpty() ? $values->implode(' / ') : null;
    }

    public function visual()
    {
        return $this->belongsTo(FormOption::class, 'visual_option_id');
    }

    /** Hasil visual untuk tanda di samping jam cek: ok (centang hijau), ng (silang merah), null = lainnya / kosong. */
    public function visualStatus(): ?string
    {
        return match (strtoupper(trim((string) $this->visual?->label))) {
            'OK' => 'ok',
            'NG' => 'ng',
            default => null,
        };
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
