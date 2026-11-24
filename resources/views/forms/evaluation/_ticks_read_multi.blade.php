{{-- Tampilan baca kontrol kepatuhan (bisa lebih dari satu centang) untuk pengecekan $c --}}
@php
    $picked = $c->compliances->pluck('id');
    $list = ($groups['compliance'] ?? collect())->concat($c->compliances->reject(fn ($o) => ($groups['compliance'] ?? collect())->contains('id', $o->id)));
@endphp
<ul class="space-y-1">
    @foreach ($list as $o)
        @php $on = $picked->contains($o->id); @endphp
        <li class="tick-read {{ $on ? 'on' : '' }}">
            <span class="tick-box">@if ($on)<x-icon name="check" :size="12" :stroke="3" />@endif</span>{{ $o->label }}
        </li>
    @endforeach
    @if ($c->compliance_other)
        <li class="tick-read on"><span class="tick-box"><x-icon name="check" :size="12" :stroke="3" /></span>Lainnya: {{ $c->compliance_other }}</li>
    @endif
</ul>
