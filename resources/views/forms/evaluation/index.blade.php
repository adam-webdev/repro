<x-layouts.app title="Form Daily Supervisor">
    @if ($canCreate)
        <x-slot:actions>
            <a href="{{ route('forms.evaluation.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /><span class="hidden sm:inline">Isi form</span></a>
        </x-slot:actions>
    @endif
<div class="space-y-4">
    @include('forms._filters')

    <div class="card">
        @if ($days->isEmpty())
            <div class="card-pad text-center py-12">
                <div class="text-[15px] font-semibold">Belum ada pengecekan pada periode ini</div>
                <p class="text-[13px] mt-1" style="color:var(--muted)">Form daily supervisor diisi saat ada masalah atau sewaktu-waktu.</p>
                @if ($canCreate)<a href="{{ route('forms.evaluation.create') }}" class="btn btn-primary mt-4"><x-icon name="plus" :size="16" />Isi Form Daily Supervisor</a>@endif
            </div>
        @else
            <div class="card-pad !py-3 border-b" style="border-color:var(--border)">
                <div class="card-title">Daftar tanggal</div>
                <div class="card-sub">Pilih tanggal untuk melihat area, lalu pilih area untuk melihat semua pengecekan di area itu.</div>
            </div>
            <ul class="divide-y" style="border-color:var(--border)">
                @foreach ($days as $day)
                    @php $date = \Illuminate\Support\Carbon::parse($day->form_date); @endphp
                    <li>
                        <a href="{{ route('forms.evaluation.day', $date->toDateString()) }}" class="flex items-center gap-3 px-4 sm:px-5 py-3.5 hover:bg-[var(--hover)]">
                            <span class="grid place-items-center w-11 h-11 rounded-xl shrink-0 leading-none text-center" style="background:var(--surface-2)">
                                <span class="block">
                                    <span class="block text-[16px] font-semibold">{{ $date->format('d') }}</span>
                                    <span class="block text-[10.5px] uppercase mt-0.5" style="color:var(--muted)">{{ $date->translatedFormat('M') }}</span>
                                </span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14.5px] font-semibold">{{ $date->translatedFormat('l, d F Y') }}</span>
                                <span class="block text-[12.5px]" style="color:var(--muted)">{{ $day->checks }} pengecekan · {{ $day->areas }} area · {{ $day->karus }} karu</span>
                            </span>
                            <x-icon name="chevron-right" :size="18" style="color:var(--muted)" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{ $days->links() }}
</div>
</x-layouts.app>
