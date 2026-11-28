<x-layouts.app title="Form Daily Supervisor">
@php
    $n = fn ($v, $d = 2) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',');
    // Semua opsi per grup, supaya hasilnya tampil seperti lembar kertas: yang dipilih tercentang, sisanya kosong
    $groups = \App\Models\FormOption::whereIn('group', ['jig', 'visual', 'compliance'])->whereNull('machine_type')
        ->where('is_active', true)->orderBy('sort')->get()->groupBy('group');
    $hasOd = $checks->contains(fn ($c) => $c->od_mm !== null);
    $measures = array_merge($hasOd ? [['od_mm', 'OD']] : [], [['d1', 'D1'], ['d2', 'D2'], ['d3', 'D3'], ['d4', 'D4'], ['thickness_mm', 'Tebal'], ['ib_height_mm', 'Tinggi IB']],
        $checks->contains(fn ($c) => $c->length_mm !== null) ? [['length_mm', 'Panjang']] : [], // data lama: satu kolom panjang
        [['p1', 'P1'], ['p2', 'P2'], ['p3', 'P3']]);
    $areaName = $area?->name ?? 'Tanpa area';
    $user = auth()->user();
@endphp
<div class="max-w-[1500px] mx-auto space-y-4">
    <div class="card card-pad">
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <div class="text-[12px]" style="color:var(--muted)">Form Daily Supervisor · PDR/PP/13</div>
                <div class="text-[18px] font-semibold mt-0.5">{{ $areaName }} · {{ $date->translatedFormat('l, d F Y') }}</div>
                <div class="text-[13px] mt-0.5" style="color:var(--ink-2)">
                    {{ $checks->count() }} pengecekan dari {{ $forms->count() }} form ·
                    {{ $forms->map(fn ($f) => $f->user ? $f->karuLabel() : null)->filter()->unique()->join(', ') ?: 'belum ada karu' }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('forms.evaluation.day', $date->toDateString()) }}" class="btn"><x-icon name="arrow-left" :size="16" />Pilih area</a>
                @if ($checks->isNotEmpty() && $user->hasRole('produksi'))
                    <a href="{{ route('forms.evaluation.area-export', [$date->toDateString(), $areaKey]) }}" class="btn"><x-icon name="download" :size="16" />Export Excel</a>
                @endif
                @if ($canCreate && $area)
                    <a href="{{ route('forms.evaluation.create', ['date' => $date->toDateString(), 'area_id' => $area->id]) }}" class="btn btn-primary"><x-icon name="plus" :size="16" />Tambah pengecekan</a>
                @endif
            </div>
        </div>
    </div>

    @if ($checks->isEmpty())
        <div class="card card-pad text-center py-12">
            <div class="text-[15px] font-semibold">Belum ada pengecekan di {{ $areaName }} pada tanggal ini</div>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="card-pad !py-3 flex flex-wrap items-center justify-between gap-2 border-b" style="border-color:var(--border)">
                <div>
                    <div class="card-title">Detail pengecekan</div>
                    <div class="card-sub">Gabungan semua karu, urut shift lalu jam cek</div>
                </div>
                <span class="chip chip-muted lg:hidden"><x-icon name="arrow-left-right" :size="13" />Geser ke samping</span>
            </div>
            <div class="sheet-scroll">
                <table class="sheet sheet-read">
                    <thead>
                        <tr>
                            <th rowspan="2" class="sheet-sticky">Jam cek</th>
                            <th rowspan="2" class="min-w-[130px]">Karu</th>
                            <th rowspan="2">Mesin</th>
                            <th rowspan="2" class="min-w-[170px]">Customer &amp; dimensi</th>
                            <th colspan="{{ count($measures) }}" class="sheet-group">Pengecekan aktual (mm)</th>
                            <th rowspan="2" class="min-w-[140px]">Jig</th>
                            <th rowspan="2" class="min-w-[140px]">Visual</th>
                            <th rowspan="2">Actual cutting<br><span class="font-normal">(pcs)</span></th>
                            <th rowspan="2" class="min-w-[220px]">Kontrol kepatuhan</th>
                            <th rowspan="2" class="min-w-[220px]">Problem / keterangan</th>
                            <th rowspan="2" class="min-w-[130px]">Evidence foto</th>
                            <th rowspan="2"><span class="sr-only">Form</span></th>
                        </tr>
                        <tr>
                            @foreach ($measures as [$k, $l])
                                <th class="sheet-sub min-w-[64px]">{{ $l }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($checks as $i => $c)
                            <tr>
                                <td class="sheet-sticky">
                                    <div class="text-[11px] font-semibold" style="color:var(--muted)">#{{ $i + 1 }}</div>
                                    <div class="font-semibold tabular-nums flex items-center gap-1.5">{{ $c->check_time ? substr($c->check_time, 0, 5) : '–' }}@if ($vs = $c->visualStatus())<span class="visual-mark {{ $vs }}" title="Visual {{ strtoupper($vs) }}" aria-label="Visual {{ strtoupper($vs) }}"><x-icon :name="$vs === 'ok' ? 'check' : 'x'" :size="12" :stroke="3" /></span>@endif</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $c->form->user?->name ?? '–' }}</div>
                                    @if ($sub = $c->form->substituteLabel())<span class="chip chip-warn !text-[11px] mt-0.5">{{ $sub }}</span>@endif
                                    <div class="text-[11.5px]" style="color:var(--muted)">Shift {{ $c->form->shift }}</div>
                                </td>
                                <td class="font-semibold whitespace-nowrap">{{ $c->machine?->code ?? '–' }}</td>
                                <td>
                                    <div class="sheet-pre"><span>Cust</span>@if ($c->customer && $user->hasRole('produksi'))<a href="{{ route('customers.show', $c->customer) }}" class="underline underline-offset-2">{{ $c->customer->name }}</a>@else{{ $c->customer?->name ?? '–' }}@endif</div>
                                    <div class="sheet-pre mt-1"><span>Size</span>{{ $c->dimension ?: '–' }}</div>
                                </td>
                                @foreach ($measures as [$k, $l])
                                    <td class="text-right tabular-nums">{{ $n($c->{$k}) }}</td>
                                @endforeach
                                <td>@include('forms.evaluation._jigs_read')</td>
                                <td>@include('forms.evaluation._ticks_read', ['g' => 'visual'])</td>
                                <td class="text-right tabular-nums">{{ $c->actual_cutting !== null ? number_format($c->actual_cutting, 0, ',', '.') : '–' }}</td>
                                <td>@include('forms.evaluation._ticks_read_multi')</td>
                                <td class="whitespace-pre-line">{{ $c->problem ?: '–' }}</td>
                                <td>@include('forms._gallery', ['photos' => $c->attachments])</td>
                                <td class="whitespace-nowrap">
                                    <div class="flex flex-col gap-1.5">
                                        @if ($user->role !== 'karu' || $c->form->user_id === $user->id)
                                            <a href="{{ route('forms.evaluation.show', $c->form) }}" class="btn !h-8">Detail</a>
                                        @endif
                                        @if (\App\Services\KaruFormService::canEdit($user, $c->form))
                                            <a href="{{ route('forms.evaluation.edit', $c->form) }}" class="btn !h-8">Edit</a>
                                            <form method="POST" action="{{ route('forms.evaluation.check.destroy', $c) }}" x-data
                                                  @submit="if (!confirm('Hapus pengecekan jam {{ $c->check_time ? substr($c->check_time, 0, 5) : '' }} di mesin {{ $c->machine?->code }}? Riwayatnya tetap tersimpan.')) $event.preventDefault()">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-danger !h-8 w-full">Hapus</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
</x-layouts.app>
