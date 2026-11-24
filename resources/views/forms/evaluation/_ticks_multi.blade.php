{{-- Kontrol kepatuhan pada baris pengecekan `c`: boleh centang lebih dari satu (+ Lainnya dengan teks). --}}
<div class="space-y-1">
    <template x-for="o in opts('compliance')" :key="o.id">
        <label class="tick">
            <input type="checkbox" :checked="c.compliance_ids.map(String).includes(String(o.id))" @change="toggleCompliance(c, o.id, $event.target.checked)">
            <span x-text="o.label"></span>
        </label>
    </template>
    <label class="tick">
        <input type="checkbox" x-model="c.compliance_other_on">
        <span>Lainnya</span>
    </label>
    <input class="input !h-9 !text-[14px]" x-show="c.compliance_other_on" x-model="c.compliance_other"
           placeholder="Tulis kontrol kepatuhan…" maxlength="150" aria-label="Kontrol kepatuhan lainnya">
    <span class="field-err" :data-error="err(`checks.${i}.compliance_option_ids`) ? 1 : null" x-text="err(`checks.${i}.compliance_option_ids`)"></span>
</div>
