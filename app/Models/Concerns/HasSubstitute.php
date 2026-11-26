<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Form karu yang bisa diisi oleh karu pengganti (kolom by_substitute & substitute_for_id). */
trait HasSubstitute
{
    public function substituteFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_for_id')->withTrashed();
    }

    /** Keterangan pengganti, mis. "Pengganti Kurniawan" atau "Pengganti"; null jika diisi karu tetap. */
    public function substituteLabel(): ?string
    {
        if (! $this->by_substitute) {
            return null;
        }

        return trim('Pengganti '.($this->substituteFor?->name ?? ''));
    }

    /** Nama karu pengisi + keterangan pengganti, mis. "Budi (Pengganti Kurniawan)". */
    public function karuLabel(): string
    {
        $name = $this->user?->name ?? '–';

        return ($label = $this->substituteLabel()) ? $name.' ('.$label.')' : $name;
    }

    /** Baris snapshot audit trail untuk keterangan pengganti. */
    protected function substituteSnapshot(): array
    {
        return $this->by_substitute ? ['Karu pengganti' => $this->substituteFor?->name ?? 'Ya'] : [];
    }
}
