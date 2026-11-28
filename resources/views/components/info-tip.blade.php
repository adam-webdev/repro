@props(['title' => 'Rumus & sumber data', 'dark' => false])
{{--
    Tombol ⓘ kecil di judul grafik / KPI: klik untuk melihat rumus & asal datanya.
    Panel dipindah ke <body> (teleport) supaya tidak terpotong kartu, posisinya mengikuti tombol & tetap di dalam layar.
--}}
<span x-data="infoTip" class="inline-flex align-middle shrink-0" @keydown.escape.window="open = false">
    <button type="button" x-ref="btn" class="info-btn {{ $dark ? 'info-btn-dark' : '' }}" @click.stop="toggle()" :aria-expanded="open"
            title="{{ $title }}" aria-label="{{ $title }}"><x-icon name="info" :size="15" /></button>
    <template x-teleport="body">
        <div x-show="open" x-cloak x-transition.opacity.duration.120ms @click.outside="open = false" :style="style"
             class="info-pop {{ $dark ? 'info-pop-dark' : '' }}" role="dialog" aria-label="{{ $title }}">
            <div class="flex items-start justify-between gap-2 mb-1.5">
                <span class="font-semibold">{{ $title }}</span>
                <button type="button" class="info-close" @click="open = false" aria-label="Tutup"><x-icon name="x" :size="14" /></button>
            </div>
            {{ $slot }}
        </div>
    </template>
</span>
