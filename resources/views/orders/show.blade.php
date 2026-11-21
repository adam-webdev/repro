<x-layouts.app :title="'Order ' . $order->order_no">
@php
    $ySap = $order->yieldSapLike();
    $sap = $order->sap;
    $diff = ($sap && $sap->gr_percent !== null && $ySap !== null) ? $sap->gr_percent - $ySap : null;
@endphp
<div class="space-y-4">
    <div class="card card-pad">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-[12px]" style="color:var(--muted)">Order SAP</div>
                <div class="text-[22px] font-semibold tracking-tight">{{ $order->order_no }}</div>
                <div class="text-[14px] mt-0.5" style="color:var(--ink-2)">{{ $order->customer?->name ?? 'Customer tidak diketahui' }}</div>
            </div>
            <div class="text-right">
                <div class="font-mono text-[13px]">{{ $order->material?->code ?? '-' }}</div>
                <div class="text-[15px] font-semibold mt-0.5">{{ $order->material?->size_label ?? '-' }}</div>
                <div class="text-[11.5px]" style="color:var(--muted)">Ø dan T dibaca dari kode material (perlu konfirmasi)</div>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mt-5">
            @foreach ([
                ['Periode', ($order->first_date?->format('d/m') ?? '-') . ' – ' . ($order->last_date?->format('d/m/Y') ?? '-')],
                ['Laporan', $order->report_count . ' baris'],
                ['Baik', number_format($order->ok_qty, 0, ',', '.') . ' pcs'],
                ['NC / Reject', number_format($order->nc_qty, 0, ',', '.') . ' / ' . number_format($order->reject_qty, 0, ',', '.')],
                ['Yield lapangan', $ySap !== null ? number_format($ySap, 2, ',', '.') . '%' : '-'],
                ['GR SAP', $sap ? number_format($sap->gr_percent, 2, ',', '.') . '% · GI ' . number_format($sap->gi_kg, 1, ',', '.') . ' kg' : 'Belum ada data SAP'],
            ] as [$l, $v])
                <div>
                    <div class="text-[12px]" style="color:var(--muted)">{{ $l }}</div>
                    <div class="text-[14px] font-medium mt-0.5">{{ $v }}</div>
                </div>
            @endforeach
        </div>
        @if ($diff !== null && (abs($diff) > config('production.anomaly.yield_diff') || $sap->gr_percent > 100))
            <x-alert type="warn" class="mt-4">Yield SAP berbeda {{ number_format($diff, 2, ',', '.') }} poin dari data lapangan{{ $sap->gr_percent > 100 ? ' dan GR SAP melebihi 100%' : '' }}, perlu ditelusuri.</x-alert>
        @endif
    </div>

    <div class="card">
        <div class="card-pad !pb-2"><div class="card-title">Riwayat produksi order ini</div><div class="card-sub">Per shift dan mesin</div></div>
        <div class="overflow-x-auto px-2 pb-2">
            <table class="tbl">
                <thead><tr><th>Tanggal</th><th>Shift</th><th>Mesin</th><th>Jam</th><th class="num">Bahan baku</th><th class="num">Baik</th><th class="num">NC</th><th class="num">Reject</th><th class="num">Downtime</th><th class="num">Cutting</th></tr></thead>
                <tbody>
                @foreach ($reports as $r)
                    @php $ct = $r->working_minutes ? $r->cutting_minutes / $r->working_minutes * 100 : null; @endphp
                    <tr>
                        <td>{{ $r->production_date->format('d/m/Y') }}</td>
                        <td><span class="chip chip-muted">S{{ $r->shift }}</span></td>
                        <td class="font-medium">{{ $r->machine?->code }}</td>
                        <td class="text-[12.5px]">{{ substr($r->start_time, 0, 5) }}–{{ substr($r->end_time, 0, 5) }}</td>
                        <td class="num">{{ $r->raw_qty }} × {{ rtrim(rtrim(number_format($r->raw_length, 2, ',', '.'), '0'), ',') }}</td>
                        <td class="num">{{ number_format($r->ok_qty, 0, ',', '.') }}</td>
                        <td class="num">{{ $r->nc_qty ? number_format($r->nc_qty, 0, ',', '.') : '–' }}</td>
                        <td class="num">{{ $r->reject_qty ? number_format($r->reject_qty, 0, ',', '.') : '–' }}</td>
                        <td class="num">{{ $r->downtime_minutes }}′</td>
                        <td class="num">{{ $ct !== null ? number_format($ct, 0) . '%' : '–' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <a href="{{ url()->previous() }}" class="btn"><x-icon name="arrow-left" :size="16" />Kembali</a>
</div>
</x-layouts.app>
