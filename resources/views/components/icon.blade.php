@props(['name', 'size' => 18, 'stroke' => 1.75])
{{-- Ikon Lucide (resources/icons/*.svg, lisensi ISC). Pemakaian: <x-icon name="upload" :size="16" /> --}}
@php
    static $cache = [];
    $inner = $cache[$name] ??= (function () use ($name) {
        $svg = @file_get_contents(resource_path("icons/{$name}.svg")) ?: '';
        return preg_match('/<svg[^>]*>(.*)<\/svg>/s', $svg, $m) ? trim($m[1]) : '';
    })();
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes->merge(['class' => 'shrink-0']) }}>{!! $inner !!}</svg>
