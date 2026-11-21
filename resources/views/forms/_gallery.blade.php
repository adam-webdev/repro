{{-- Foto bukti (klik untuk perbesar). $photos = koleksi Attachment --}}
@if ($photos->count())
    <div x-data="gallery" class="flex flex-wrap gap-2">
        @foreach ($photos as $p)
            <button type="button" class="thumb" @click="open('{{ $p->url() }}')"><img src="{{ $p->url() }}" alt="Foto bukti" loading="lazy"></button>
        @endforeach
        <template x-teleport="body">
            <div x-show="src" x-cloak class="fixed inset-0 z-50 grid place-items-center p-4" style="background:rgba(0,0,0,.85)" @click="src = null" @keydown.escape.window="src = null">
                <img :src="src" class="max-w-full max-h-full rounded-lg" alt="Foto bukti">
            </div>
        </template>
    </div>
@else
    <span class="text-[13px]" style="color:var(--muted)">Tidak ada foto.</span>
@endif
