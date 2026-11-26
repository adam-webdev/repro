{{-- Pilihan keterangan Set Up / Bongkar / DT yang bisa dicari (daftar DT panjang). Memakai `it`, `p`, `i`, `j` dari form. --}}
<div class="relative min-w-0" x-data="{ open: false, q: '' }" @click.outside="open = false" @keydown.escape.stop="open = false">
    <button type="button" class="input flex items-center gap-2 text-left" @click="open = !open; q = ''; open && $nextTick(() => $refs.q.focus())"
            :class="err(`items.${i}.problems.${j}.option_id`) && 'is-invalid'" :data-error="err(`items.${i}.problems.${j}.option_id`) ? 1 : null"
            :aria-expanded="open" aria-haspopup="listbox">
        <span class="flex-1 min-w-0 truncate" :style="!p.option_id && 'color:var(--muted)'"
              x-text="problemLabel(it, p) || (p.kind === 'dt' ? 'Pilih keterangan DT' : 'Pilih ' + kindLabel(p.kind))"></span>
        <x-icon name="chevron-down" :size="16" class="shrink-0" style="color:var(--muted)" />
    </button>
    <div x-show="open" x-cloak class="combo-pop">
        <div class="p-2 border-b" style="border-color:var(--border)">
            <input x-ref="q" type="search" class="input" x-model="q" placeholder="Ketik untuk mencari…" aria-label="Cari keterangan"
                   @keydown.enter.prevent="const first = searchOptions(it, p.kind, q)[0]; if (first) { p.option_id = first.id; open = false }">
        </div>
        <div class="combo-list" role="listbox">
            <template x-for="o in searchOptions(it, p.kind, q)" :key="o.id">
                <button type="button" class="combo-item" :class="String(o.id) === String(p.option_id) && 'on'" @click="p.option_id = o.id; open = false" x-text="o.label"></button>
            </template>
            <div class="px-3 py-2 text-[13px]" style="color:var(--muted)" x-show="!searchOptions(it, p.kind, q).length">Tidak ditemukan. Pilih "Lainnya" lalu ketik manual.</div>
            <button type="button" class="combo-item" x-show="p.kind !== 'bongkar'" :class="p.option_id === 'other' && 'on'" @click="p.option_id = 'other'; open = false">Lainnya (isi manual)</button>
        </div>
    </div>
</div>
