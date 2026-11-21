{{-- Bagian atas form karu: tanggal (boleh mundur), shift, area, nama karu otomatis dari akun login. --}}
@php $owner = $form?->user ?? auth()->user(); @endphp
<div class="card card-pad space-y-3">
    <div class="flex items-center justify-between gap-2">
        <div class="card-title">Informasi pengisian</div>
        <span class="chip chip-muted">{{ $owner->name }}{{ $owner->employee?->code ? ' · '.$owner->employee->code : '' }}</span>
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
                @foreach ([1, 2, 3] as $s)
                    <button type="button" :class="Number(f.shift) === {{ $s }} && 'on'" @click="f.shift = {{ $s }}">Shift {{ $s }}</button>
                @endforeach
            </div>
        </div>
        <label class="block">
            <span class="label">Area</span>
            <select class="input" x-model="f.area_id">
                <option value="">Semua area</option>
                @foreach ($areas as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
            </select>
        </label>
    </div>
    <p class="text-[12px]" style="color:var(--muted)">Kepala Regu terisi otomatis dari akun yang login. Tanggal boleh diisi mundur (back date).</p>
</div>
