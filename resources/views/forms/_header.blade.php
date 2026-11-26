{{-- Bagian atas form karu: tanggal (boleh mundur), shift, area, nama karu otomatis dari akun login. --}}
@php
    $owner = $form?->user ?? auth()->user();
    $ctx = \App\Services\KaruFormService::headerContext(auth()->user(), $form);
    $lockedArea = $ctx['lockedAreaId'] ? $areas->firstWhere('id', $ctx['lockedAreaId']) : null;
@endphp
<div class="card card-pad !py-3 flex flex-wrap items-center gap-3" x-show="draftRestored" x-cloak style="border-color:var(--warn, #d97706)">
    <span class="text-[13px] flex-1 min-w-0">Isian yang belum tersimpan sebelumnya dipulihkan. Periksa lalu tekan <b>Simpan</b>.</span>
    <button type="button" class="btn !h-8" @click="discardDraft()">Mulai dari awal</button>
    <button type="button" class="btn !h-8" @click="draftRestored = false">Tutup</button>
</div>
<div class="card card-pad space-y-3">
    <div class="flex items-center justify-between gap-2">
        <div class="card-title">Informasi pengisian</div>
        <span class="flex flex-wrap justify-end gap-1.5">
            <span class="chip chip-muted">{{ $owner->name }}{{ $owner->employee?->code ? ' · '.$owner->employee->code : '' }}</span>
            @if ($ctx['bySubstitute'])<span class="chip chip-warn">Pengganti</span>@endif
        </span>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <label class="block">
            <span class="label">Tanggal *</span>
            <input type="date" class="input" x-model="f.form_date" max="{{ now()->toDateString() }}" :class="err('form_date') && 'is-invalid'" :data-error="err('form_date') ? 1 : null">
            <span class="field-err" x-text="err('form_date')"></span>
        </label>
        <div>
            <span class="label">Shift *</span>
            <div class="seg-lg">
                @foreach (config('production.karu_shifts') as $s => $time)
                    <button type="button" :class="Number(f.shift) === {{ $s }} && 'on'" @click="f.shift = {{ $s }}">Shift {{ $s }} <span class="font-normal opacity-70">{{ $time['start'] }}–{{ $time['end'] }}</span></button>
                @endforeach
            </div>
        </div>
        @if ($lockedArea)
            {{-- Karu tetap: area mengikuti area tugas di akun, tidak bisa diganti --}}
            <div>
                <span class="label">Area</span>
                <div class="input flex items-center gap-2" style="background:var(--surface-2)" title="Area tugas Anda (diatur Admin Master)">
                    <x-icon name="lock" :size="15" /><span class="font-medium">{{ $lockedArea->name }}</span>
                </div>
            </div>
        @else
            <label class="block">
                <span class="label">Area{{ $ctx['bySubstitute'] ? ' *' : '' }}</span>
                <select class="input" x-model="f.area_id" :class="err('area_id') && 'is-invalid'" :data-error="err('area_id') ? 1 : null">
                    <option value="">{{ $ctx['bySubstitute'] ? 'Pilih area' : 'Semua area' }}</option>
                    @foreach ($areas as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                </select>
                <span class="field-err" x-text="err('area_id')"></span>
            </label>
        @endif
    </div>
    @if ($ctx['bySubstitute'])
        <label class="block">
            <span class="label">Menggantikan karu (opsional)</span>
            <select class="input w-full" x-model="f.substitute_for_id" :class="err('substitute_for_id') && 'is-invalid'">
                <option value="">– Tidak diisi –</option>
                @foreach (\App\Models\User::replaceableKarus() as $k)
                    <option value="{{ $k->id }}">{{ $k->name }}{{ $k->area_id ? ' · '.($areas->firstWhere('id', $k->area_id)?->name) : '' }}</option>
                @endforeach
            </select>
            <span class="field-err" x-text="err('substitute_for_id')"></span>
        </label>
    @endif
    <p class="text-[12px]" style="color:var(--muted)">Kepala Regu terisi otomatis dari akun yang login. Tanggal boleh diisi mundur (back date).@if ($lockedArea) Area terkunci sesuai area tugas Anda.@endif</p>
</div>
