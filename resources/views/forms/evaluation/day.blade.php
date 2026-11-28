<x-layouts.app title="Form Daily Supervisor">
<div class="space-y-4 max-w-5xl">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('forms.evaluation.index') }}" class="btn"><x-icon name="arrow-left" :size="16" />Daftar tanggal</a>
        <div class="min-w-0 flex-1">
            <div class="text-[12px]" style="color:var(--muted)">Form Daily Supervisor · pilih area</div>
            <div class="text-[18px] font-semibold">{{ $date->translatedFormat('l, d F Y') }}</div>
        </div>
    </div>

    {{-- Satu kotak = satu area; seluruh kotak bisa diklik untuk membuka detail area itu --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 auto-rows-fr gap-4">
        @foreach ($cards as $card)
            @php $s = $card['stat']; @endphp
            <div class="relative h-full">
                <a href="{{ route('forms.evaluation.area', [$date->toDateString(), $card['key']]) }}"
                   class="card h-full flex flex-col items-center justify-center text-center gap-2 p-6 min-h-[230px] transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2 {{ $s ? '' : 'opacity-75' }}"
                   style="{{ $s ? 'border-color:var(--ink-2)' : '' }}">
                    <span class="grid place-items-center w-14 h-14 rounded-2xl" style="background:var(--surface-2)"><x-icon name="map-pin" :size="26" /></span>
                    <span class="text-[22px] font-semibold leading-tight">{{ $card['name'] }}</span>
                    @if ($s)
                        <span class="chip chip-ok">{{ $s->checks }} pengecekan</span>
                        <span class="text-[12.5px]" style="color:var(--ink-2)">Shift {{ implode(', ', $s->shifts) }}</span>
                    @else
                        <span class="chip chip-muted">Belum ada pengecekan</span>
                    @endif
                    <span class="text-[12.5px] font-medium inline-flex items-center gap-1 mt-1" style="color:var(--ink-2)">Lihat detail <x-icon name="chevron-right" :size="15" /></span>
                </a>
                @if ($canCreate && $card['key'] !== \App\Http\Controllers\EvaluationFormController::NO_AREA)
                    <a href="{{ route('forms.evaluation.create', ['date' => $date->toDateString(), 'area_id' => $card['key']]) }}"
                       class="icon-btn absolute top-3 right-3 z-10" title="Tambah pengecekan di {{ $card['name'] }}" aria-label="Tambah pengecekan di {{ $card['name'] }}">
                        <x-icon name="plus" :size="18" />
                    </a>
                @endif
            </div>
        @endforeach

        {{-- Repair & Packing: form & data baru (proyek berikutnya), belum bisa dibuka --}}
        @foreach ([['Repair', 'wrench'], ['Packing', 'package']] as [$name, $icon])
            <div class="card h-full flex flex-col items-center justify-center text-center gap-2 p-6 min-h-[230px] opacity-60 cursor-not-allowed select-none"
                 aria-disabled="true" title="{{ $name }}: segera hadir">
                <span class="grid place-items-center w-14 h-14 rounded-2xl" style="background:var(--surface-2)"><x-icon :name="$icon" :size="26" /></span>
                <span class="text-[22px] font-semibold leading-tight">{{ $name }}</span>
                <span class="chip chip-muted">Segera hadir</span>
                <span class="text-[12.5px]" style="color:var(--muted)">Form &amp; data {{ strtolower($name) }} sedang disiapkan</span>
            </div>
        @endforeach
    </div>
</div>
</x-layouts.app>
