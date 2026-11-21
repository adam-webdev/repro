@props(['variant' => 'full'])
{{-- Logo SPINDO; versi gelap (tulisan putih) otomatis dipakai saat mode gelap. variant: full | mark --}}
@php $file = $variant === 'mark' ? 'spindo-mark' : 'spindo-logo'; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0']) }}>
    <img src="{{ asset("brand/{$file}.png") }}" alt="SPINDO" class="theme-ico-light h-full w-auto" draggable="false">
    <img src="{{ asset("brand/{$file}-dark.png") }}" alt="SPINDO" class="theme-ico-dark h-full w-auto" draggable="false">
</span>
