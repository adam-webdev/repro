<x-layouts.app title="Form Rekapan">
    <x-slot:actions>
        <a href="{{ route('forms.production.export', request()->only(['from', 'to', 'shift', 'area_id', 'user_id', 'machine_id'])) }}" class="btn"><x-icon name="download" :size="16" /><span class="hidden sm:inline">Export Excel</span></a>
        @if ($canCreate)
            <a href="{{ route('forms.production.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /><span class="hidden sm:inline">Isi form</span></a>
        @endif
    </x-slot:actions>
@php
    // Karu hanya melihat form miliknya sendiri, jadi kolom nama & riwayat input tidak perlu ditampilkan.
    $isAdmin = auth()->user()->role !== 'karu';
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp
<div class="space-y-4">
    @include('forms._filters')

    <div class="card">
        @if ($forms->isEmpty())
            <div class="card-pad text-center py-12">
                <div class="text-[15px] font-semibold">Belum ada form pada periode ini</div>
                <p class="text-[13px] mt-1" style="color:var(--muted)">Ubah filter tanggal, atau isi form baru.</p>
                @if ($canCreate)<a href="{{ route('forms.production.create') }}" class="btn btn-primary mt-4"><x-icon name="plus" :size="16" />Isi Form Rekapan</a>@endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr>
                        <th>Tanggal</th><th>Shift</th>
                        @if ($isAdmin)<th>Karu</th>@endif
                        <th>Area</th><th>Mesin · PRO</th>
                        <th class="num">Hasil OK</th><th class="num">Set up</th><th class="num">Bongkar + DT</th><th class="num">Foto</th>
                        @if ($isAdmin)<th>Diinput</th><th>Terakhir diedit</th>@endif
                        <th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($forms as $form)
                        @php
                            $problems = $form->items->flatMap->problems;
                            $su = $problems->where('kind', 'setup')->sum('minutes');
                            $dt = $problems->whereIn('kind', ['dt', 'bongkar'])->sum('minutes');
                            $photos = $form->items->sum('attachments_count');
                            $pros = $form->items->map(fn ($i) => trim(($i->machine?->code ?? '-').($i->order_no ? ' · '.$i->order_no : '')));
                        @endphp
                        <tr>
                            <td class="font-medium">{{ $form->form_date->translatedFormat('D, d M Y') }}</td>
                            <td>{{ $form->shift }}</td>
                            @if ($isAdmin)<td>{{ $form->user?->name }}@if ($sub = $form->substituteLabel())<span class="block text-[11.5px]" style="color:var(--muted)">{{ $sub }}</span>@endif</td>@endif
                            <td>{{ $form->area?->name ?? 'Semua' }}</td>
                            <td class="!whitespace-normal min-w-[180px] max-w-[320px]">
                                <span title="{{ $pros->join(', ') }}">{{ $pros->take(3)->join(', ') }}{{ $pros->count() > 3 ? ' +'.($pros->count() - 3) : '' }}</span>
                                <span class="block text-[11.5px]" style="color:var(--muted)">{{ $form->items->count() }} PRO</span>
                            </td>
                            <td class="num">{{ $n($form->items->sum('ok_qty')) }}</td>
                            <td class="num">{{ $su ? $n($su).' mnt' : '–' }}</td>
                            <td class="num">{{ $dt ? $n($dt).' mnt' : '–' }}</td>
                            <td class="num">{{ $photos ?: '–' }}</td>
                            @if ($isAdmin)
                                <td><span class="block">{{ $form->creator?->name ?? $form->user?->name }}</span><span class="text-[11.5px]" style="color:var(--muted)">{{ $form->created_at->format('d/m/Y H:i') }}</span></td>
                                <td><span class="block">{{ $form->updater?->name ?? '–' }}</span><span class="text-[11.5px]" style="color:var(--muted)">{{ $form->updated_at->format('d/m/Y H:i') }}</span></td>
                            @endif
                            <td class="text-right">
                                <div class="inline-flex gap-1.5">
                                    <a href="{{ route('forms.production.show', $form) }}" class="btn !h-8">Detail</a>
                                    @if (\App\Services\KaruFormService::canEdit(auth()->user(), $form))
                                        <a href="{{ route('forms.production.edit', $form) }}" class="btn !h-8">Edit</a>
                                        <form method="POST" action="{{ route('forms.production.destroy', $form) }}" x-data
                                              @submit="if (!confirm('Hapus form rekapan {{ $form->form_date->format('d/m/Y') }} shift {{ $form->shift }}? Riwayatnya tetap tersimpan.')) $event.preventDefault()">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-danger !h-8">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $forms->links() }}
</div>
</x-layouts.app>
