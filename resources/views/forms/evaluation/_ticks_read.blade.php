{{-- Tampilan baca untuk satu grup centang ($g) pada pengecekan $c, memakai $groups dari show.blade.php --}}
@php
    $picked = $c->{$g};
    $list = ($groups[$g] ?? collect());
    if ($picked && ! $list->contains('id', $picked->id)) {
        $list = $list->push($picked); // opsi yang sudah dinonaktifkan tetap tampil kalau dulu dipilih
    }
    $other = $c->{$g.'_other'};
@endphp
<ul class="space-y-1">
    @foreach ($list as $o)
        @php $on = $picked?->id === $o->id; @endphp
        <li class="tick-read {{ $on ? 'on' : '' }}">
            <span class="tick-box">@if ($on)<x-icon name="check" :size="12" :stroke="3" />@endif</span>{{ $o->label }}
        </li>
    @endforeach
    @if ($other)
        <li class="tick-read on"><span class="tick-box"><x-icon name="check" :size="12" :stroke="3" /></span>Lainnya: {{ $other }}</li>
    @endif
</ul>
