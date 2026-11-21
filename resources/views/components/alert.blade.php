@props(['type' => 'info'])
{{-- Pesan netral dengan ikon status. type: ok | warn | crit | info --}}
@php
    $icon = ['ok' => 'circle-check-big', 'warn' => 'triangle-alert', 'crit' => 'circle-alert', 'info' => 'info'][$type] ?? 'info';
@endphp
<div {{ $attributes->merge(['class' => "alert alert-{$type}", 'role' => $type === 'crit' ? 'alert' : 'status']) }}>
    <x-icon :name="$icon" :size="17" />
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
