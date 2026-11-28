{{-- Jig pada baris pengecekan `c`: boleh centang lebih dari satu (+ Lainnya).
     JIG In / JIG Out dicentang → muncul kotak "Ukuran jig" (wajib), seperti foto pada Pengecekan visual pipa. --}}
<div class="space-y-1">
    <template x-for="o in opts('jig')" :key="o.id">
        <div>
            <label class="tick">
                <input type="checkbox" :checked="hasJig(c, o.id)" @change="toggleJig(c, o.id, $event.target.checked)">
                <span x-text="o.label"></span>
            </label>
            <div x-show="hasJig(c, o.id) && jigNeedsSize(o.id)" x-cloak class="mt-1 mb-1.5 rounded-lg p-2 space-y-1" style="background:var(--surface-2)">
                <input class="input !h-9 !text-[14px]" x-model="c.jig_sizes[o.id]" maxlength="40" :placeholder="'Ukuran ' + o.label + ' *'"
                       :aria-label="'Ukuran ' + o.label" :class="err(`checks.${i}.jig_sizes.${o.id}`) && 'is-invalid'"
                       :data-error="err(`checks.${i}.jig_sizes.${o.id}`) ? 1 : null">
                <span class="field-err" x-text="err(`checks.${i}.jig_sizes.${o.id}`)"></span>
            </div>
        </div>
    </template>
    <label class="tick">
        <input type="checkbox" x-model="c.jig_other_on">
        <span>Lainnya</span>
    </label>
    <input class="input !h-9 !text-[14px]" x-show="c.jig_other_on" x-model="c.jig_other" placeholder="Tulis jig…" maxlength="150" aria-label="Jig lainnya">
    <span class="field-err" :data-error="err(`checks.${i}.jig_option_ids`) ? 1 : null" x-text="err(`checks.${i}.jig_option_ids`)"></span>
</div>
