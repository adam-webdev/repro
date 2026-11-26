{{-- Kontrol kepatuhan pada baris pengecekan `c`: boleh centang lebih dari satu (+ Lainnya dengan teks).
     "Pengecekan visual pipa" dicentang → wajib unggah foto atau isi link gambar. --}}
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

    <div x-show="hasPipe(c)" x-cloak class="mt-2 rounded-lg p-2.5 space-y-2" style="background:var(--surface-2)"
         :class="(err(`checks.${i}.pipe_attachments`) || err(`checks.${i}.pipe_photo_url`)) && 'ring-1 ring-[var(--st-crit)]'">
        <div class="text-[12px] font-semibold">Foto visual pipa * <span class="font-normal" style="color:var(--muted)">(foto atau link)</span></div>
        @include('forms.evaluation._photo_strip', ['key' => 'pipe_photos'])
        <input type="url" inputmode="url" class="input !h-9 !text-[14px]" x-model="c.pipe_photo_url" maxlength="500"
               placeholder="atau tempel link gambar (https://…)" aria-label="Link gambar visual pipa">
        <span class="field-err" :data-error="(err(`checks.${i}.pipe_attachments`) || err(`checks.${i}.pipe_photo_url`)) ? 1 : null"
              x-text="err(`checks.${i}.pipe_attachments`) || err(`checks.${i}.pipe_photo_url`)"></span>
    </div>
</div>
