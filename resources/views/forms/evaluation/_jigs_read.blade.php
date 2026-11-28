{{-- Tampilan baca jig (bisa lebih dari satu centang, JIG In / Out dengan ukuran) untuk pengecekan $c --}}
@php
    $picked = $c->jigs->keyBy('id');
    $list = ($groups['jig'] ?? collect())->concat($c->jigs->reject(fn ($o) => ($groups['jig'] ?? collect())->contains('id', $o->id)));
@endphp
<ul class="space-y-1">
    @foreach ($list as $o)
        @php $on = $picked->get($o->id); @endphp
        <li class="tick-read {{ $on ? 'on' : '' }}">
            <span class="tick-box">@if ($on)<x-icon name="check" :size="12" :stroke="3" />@endif</span>
            <span>{{ $o->label }}@if ($on?->pivot->size)<span class="block text-[11.5px] font-semibold">Ukuran: {{ $on->pivot->size }}</span>@endif</span>
        </li>
    @endforeach
    @if ($c->jig_other)
        <li class="tick-read on"><span class="tick-box"><x-icon name="check" :size="12" :stroke="3" /></span>Lainnya: {{ $c->jig_other }}</li>
    @endif
</ul>
