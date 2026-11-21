{{-- Foto bukti: $row = nama variabel Alpine (it / c). Bisa banyak foto, dari kamera atau galeri. --}}
<div>
    <span class="label">{{ $label ?? 'Dokumentasi foto' }}</span>
    <div class="flex flex-wrap gap-2">
        <template x-for="(p, pi) in {{ $row }}.photos" :key="pi">
            <div class="thumb">
                <img :src="p.url" alt="Foto bukti">
                <div x-show="p.uploading" class="absolute inset-0 grid place-items-center text-[11px] font-semibold" style="background:rgba(0,0,0,.45);color:#fff">Upload…</div>
                <div x-show="p.error" class="absolute inset-0 grid place-items-center p-1 text-center text-[10.5px] font-semibold" style="background:rgba(160,30,30,.8);color:#fff" x-text="p.error"></div>
                <button type="button" @click="removePhoto({{ $row }}, pi)" class="absolute top-1 right-1 grid place-items-center w-6 h-6 rounded-full text-[12px]"
                        style="background:rgba(0,0,0,.6);color:#fff" aria-label="Hapus foto"><x-icon name="x" :size="13" :stroke="2.25" /></button>
            </div>
        </template>
        <label class="thumb grid place-items-center cursor-pointer text-center" style="border-style:dashed">
            <span class="grid justify-items-center gap-1 text-[11.5px]" style="color:var(--ink-2)"><x-icon name="image-plus" :size="20" />Foto</span>
            <input type="file" accept="image/*" multiple class="hidden" @change="addPhotos({{ $row }}, $event.target.files); $event.target.value = ''">
        </label>
    </div>
    <p class="text-[11.5px] mt-1" style="color:var(--muted)">Ambil dari kamera atau galeri. Bisa lebih dari satu foto, otomatis diperkecil sebelum diunggah.</p>
</div>
