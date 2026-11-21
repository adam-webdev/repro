{{-- Pilihan bergaya centang untuk satu grup ($g: jig | visual | compliance) di baris pengecekan `c`.
     Tersimpan satu pilihan per grup: mencentang satu opsi melepas yang lain, ketuk lagi untuk mengosongkan. --}}
<div class="space-y-1">
    <template x-for="o in opts('{{ $g }}')" :key="o.id">
        <label class="tick">
            <input type="checkbox" :checked="String(c.{{ $g }}_option_id) === String(o.id)"
                   @change="c.{{ $g }}_option_id = $event.target.checked ? o.id : ''">
            <span x-text="o.label"></span>
        </label>
    </template>
    <label class="tick">
        <input type="checkbox" :checked="c.{{ $g }}_option_id === 'other'" @change="c.{{ $g }}_option_id = $event.target.checked ? 'other' : ''">
        <span>Lainnya</span>
    </label>
    <input class="input !h-9 !text-[14px]" x-show="c.{{ $g }}_option_id === 'other'" x-model="c.{{ $g }}_other"
           placeholder="Tulis {{ strtolower($l) }}…" maxlength="150" aria-label="{{ $l }} lainnya">
</div>
