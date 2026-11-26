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
@if ($c->pipePhotos->isNotEmpty() || $c->pipe_photo_url)
    <div class="mt-2 space-y-1.5">
        <div class="text-[11.5px] font-semibold" style="color:var(--muted)">Foto visual pipa</div>
        @if ($c->pipePhotos->isNotEmpty())
            @include('forms._gallery', ['photos' => $c->pipePhotos])
        @endif
        @if ($c->pipe_photo_url)
            <a href="{{ $c->pipe_photo_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-[12.5px] underline underline-offset-2 break-all"><x-icon name="link" :size="13" />Buka link gambar</a>
        @endif
    </div>
@endif
