{{-- Foto kecil di baris pengecekan `c`. $key = photos (evidence umum) | pipe_photos (visual pipa). --}}
<div class="flex flex-wrap gap-1.5">
    <template x-for="(p, pi) in c.{{ $key }}" :key="pi">
        <div class="thumb !w-12 !h-12">
            <img :src="p.url" alt="Foto bukti">
            <div x-show="p.uploading" class="absolute inset-0 grid place-items-center text-[9.5px] font-semibold" style="background:rgba(0,0,0,.45);color:#fff">…</div>
            <div x-show="p.error" class="absolute inset-0" style="background:rgba(160,30,30,.75)" :title="p.error"></div>
            <button type="button" @click="removePhoto(c, pi, '{{ $key }}')" class="absolute top-0.5 right-0.5 grid place-items-center w-5 h-5 rounded-full"
                    style="background:rgba(0,0,0,.6);color:#fff" aria-label="Hapus foto"><x-icon name="x" :size="11" :stroke="2.25" /></button>
        </div>
    </template>
    <label class="thumb !w-12 !h-12 grid place-items-center cursor-pointer" style="border-style:dashed" title="Ambil foto dengan kamera" aria-label="Ambil foto dengan kamera">
        <x-icon name="camera" :size="18" style="color:var(--ink-2)" />
        <input type="file" accept="image/*" capture="environment" class="hidden" @change="addPhotos(c, $event.target.files, '{{ $key }}'); $event.target.value = ''">
    </label>
</div>
