<x-layouts.app title="Form Evaluasi">
    @if ($canCreate)
        <x-slot:actions>
            <a href="{{ route('forms.evaluation.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /><span class="hidden sm:inline">Isi form</span></a>
        </x-slot:actions>
    @endif
@php
    // Karu hanya melihat form miliknya sendiri, jadi kolom nama & riwayat input tidak perlu ditampilkan.
    $isAdmin = auth()->user()->role !== 'karu';
@endphp
<div class="space-y-4">
    @include('forms._filters')

    <div class="card">
        @if ($forms->isEmpty())
            <div class="card-pad text-center py-12">
                <div class="text-[15px] font-semibold">Belum ada form pada periode ini</div>
                <p class="text-[13px] mt-1" style="color:var(--muted)">Form evaluasi diisi saat ada masalah atau sewaktu-waktu.</p>
                @if ($canCreate)<a href="{{ route('forms.evaluation.create') }}" class="btn btn-primary mt-4"><x-icon name="plus" :size="16" />Isi Form Evaluasi</a>@endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr>
                        <th>Tanggal</th><th>Shift</th>
                        @if ($isAdmin)<th>Karu</th>@endif
                        <th>Area</th><th>Mesin</th><th class="num">Pengecekan</th><th>Visual</th><th class="num">Foto</th>
                        @if ($isAdmin)<th>Diinput</th><th>Terakhir diedit</th>@endif
                        <th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($forms as $form)
                        @php
                            $ng = $form->checks->filter(fn ($c) => $c->visual && $c->visual->label !== 'OK')->count();
                            $photos = $form->checks->sum('attachments_count');
                            $machines = $form->checks->map(fn ($c) => $c->machine?->code)->filter()->unique();
                        @endphp
                        <tr>
                            <td class="font-medium">{{ $form->form_date->translatedFormat('D, d M Y') }}</td>
                            <td>{{ $form->shift }}</td>
                            @if ($isAdmin)<td>{{ $form->user?->name }}</td>@endif
                            <td>{{ $form->area?->name ?? 'Semua' }}</td>
                            <td class="!whitespace-normal min-w-[140px] max-w-[300px]">{{ $machines->join(', ') }}</td>
                            <td class="num">{{ $form->checks->count() }}</td>
                            <td>@if ($ng)<span class="chip chip-crit">{{ $ng }} tidak OK</span>@else<span class="chip chip-ok">OK</span>@endif</td>
                            <td class="num">{{ $photos ?: '–' }}</td>
                            @if ($isAdmin)
                                <td><span class="block">{{ $form->creator?->name ?? $form->user?->name }}</span><span class="text-[11.5px]" style="color:var(--muted)">{{ $form->created_at->format('d/m/Y H:i') }}</span></td>
                                <td><span class="block">{{ $form->updater?->name ?? '–' }}</span><span class="text-[11.5px]" style="color:var(--muted)">{{ $form->updated_at->format('d/m/Y H:i') }}</span></td>
                            @endif
                            <td class="text-right">
                                <div class="inline-flex gap-1.5">
                                    <a href="{{ route('forms.evaluation.show', $form) }}" class="btn !h-8">Detail</a>
                                    @if (\App\Services\KaruFormService::canEdit(auth()->user(), $form))
                                        <a href="{{ route('forms.evaluation.edit', $form) }}" class="btn !h-8">Edit</a>
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
