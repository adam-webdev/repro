<x-layouts.app title="Form Daily Supervisor">
@php
    $n = fn ($v, $d = 2) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',');
    // Semua opsi per grup, supaya hasilnya tampil seperti lembar kertas: yang dipilih tercentang, sisanya kosong
    $groups = \App\Models\FormOption::whereIn('group', ['jig', 'visual', 'compliance'])->whereNull('machine_type')
        ->where('is_active', true)->orderBy('sort')->get()->groupBy('group');
    $hasOd = $form->checks->contains(fn ($c) => $c->od_mm !== null); // data lama yang masih punya kolom OD terpisah
    // planning sekarang diisi di Form Rekapan; kolom ini hanya tampil untuk data lama
    $hasPlanning = $form->checks->contains(fn ($c) => $c->planned_start || $c->planned_qty !== null);
    $measures = array_merge($hasOd ? [['od_mm', 'OD']] : [], [['d1', 'D1'], ['d2', 'D2'], ['d3', 'D3'], ['d4', 'D4'], ['thickness_mm', 'Tebal'], ['ib_height_mm', 'Tinggi IB'], ['length_mm', 'Panjang']]);
@endphp
<div class="max-w-[1400px] mx-auto space-y-4">
    <div class="max-w-4xl">
        @include('forms._show_header', ['kind' => 'Form Daily Supervisor · PDR/PP/13', 'editRoute' => 'forms.evaluation.edit', 'deleteRoute' => 'forms.evaluation.destroy', 'exportRoute' => 'forms.evaluation.export', 'exportLabel' => 'Export PDR/PP/13'])
    </div>

    <div class="card overflow-hidden">
        <div class="card-pad !py-3 flex flex-wrap items-center justify-between gap-2 border-b" style="border-color:var(--border)">
            <div>
                <div class="card-title">Detail pengecekan</div>
                <div class="card-sub">{{ $form->checks->count() }} baris pengecekan</div>
            </div>
            <span class="chip chip-muted lg:hidden"><x-icon name="arrow-left-right" :size="13" />Geser ke samping</span>
        </div>
        <div class="sheet-scroll">
            <table class="sheet sheet-read">
                <thead>
                    <tr>
                        <th rowspan="2" class="sheet-sticky">Jam cek</th>
                        <th rowspan="2">Mesin</th>
                        <th rowspan="2" class="min-w-[170px]">Customer &amp; dimensi</th>
                        @if ($hasPlanning)<th rowspan="2">Start jam<br><span class="font-normal">planning</span></th>@endif
                        <th colspan="{{ count($measures) }}" class="sheet-group">Pengecekan aktual (mm)</th>
                        <th rowspan="2" class="min-w-[140px]">Jig</th>
                        <th rowspan="2" class="min-w-[140px]">Visual</th>
                        <th rowspan="2">Actual cutting<br><span class="font-normal">(pcs)</span></th>
                        @if ($hasPlanning)<th rowspan="2">Qty planning<br><span class="font-normal">(pcs)</span></th>@endif
                        <th rowspan="2" class="min-w-[200px]">Kontrol kepatuhan</th>
                        <th rowspan="2" class="min-w-[220px]">Problem / keterangan</th>
                        <th rowspan="2" class="min-w-[130px]">Evidence foto</th>
                    </tr>
                    <tr>
                        @foreach ($measures as [$k, $l])
                            <th class="sheet-sub min-w-[64px]">{{ $l }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($form->checks as $c)
                        <tr>
                            <td class="sheet-sticky">
                                <div class="text-[11px] font-semibold" style="color:var(--muted)">#{{ $c->seq }}</div>
                                <div class="font-semibold tabular-nums">{{ $c->check_time ? substr($c->check_time, 0, 5) : '–' }}</div>
                            </td>
                            <td class="font-semibold whitespace-nowrap">{{ $c->machine?->code ?? '–' }}</td>
                            <td>
                                <div class="sheet-pre"><span>Cust</span>@if ($c->customer && auth()->user()->hasRole('produksi'))<a href="{{ route('customers.show', $c->customer) }}" class="underline underline-offset-2">{{ $c->customer->name }}</a>@else{{ $c->customer?->name ?? '–' }}@endif</div>
                                <div class="sheet-pre mt-1"><span>Size</span>{{ $c->dimension ?: '–' }}</div>
                            </td>
                            @if ($hasPlanning)<td class="tabular-nums">{{ $c->planned_start ? substr($c->planned_start, 0, 5) : '–' }}</td>@endif
                            @foreach ($measures as [$k, $l])
                                <td class="text-right tabular-nums">{{ $n($c->{$k}) }}</td>
                            @endforeach
                            @foreach (['jig', 'visual'] as $g)
                                <td>@include('forms.evaluation._ticks_read', ['g' => $g])</td>
                            @endforeach
                            <td class="text-right tabular-nums">{{ $c->actual_cutting !== null ? number_format($c->actual_cutting, 0, ',', '.') : '–' }}</td>
                            @if ($hasPlanning)<td class="text-right tabular-nums">{{ $c->planned_qty !== null ? number_format($c->planned_qty, 0, ',', '.') : '–' }}</td>@endif
                            <td>@include('forms.evaluation._ticks_read_multi')</td>
                            <td class="whitespace-pre-line">{{ $c->problem ?: '–' }}</td>
                            <td>@include('forms._gallery', ['photos' => $c->attachments])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="max-w-4xl space-y-4">
        @if (auth()->user()->role !== 'karu')
            @include('forms._history')
        @endif
        <a href="{{ $backUrl }}" class="btn"><x-icon name="arrow-left" :size="16" />Kembali</a>
    </div>
</div>
</x-layouts.app>
