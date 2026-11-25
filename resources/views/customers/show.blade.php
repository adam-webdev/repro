<x-layouts.app :title="'Customer ' . $customer->name">
@php
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
    $d = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '–';
    $monthLabel = fn ($ym) => \Carbon\Carbon::parse($ym . '-01')->translatedFormat('M Y');
    $filtered = $from || $to;
    $activeMaterial = $materialId ? $materials->get($materialId) : null;
@endphp
<div class="space-y-4">
    {{-- ===================== DETAIL CUSTOMER ===================== --}}
    <div class="card card-pad">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-[12px]" style="color:var(--muted)">Customer</div>
                <div class="text-[22px] font-semibold tracking-tight">{{ $customer->name }}</div>
                <div class="text-[13px] mt-0.5" style="color:var(--ink-2)">
                    @if ($filtered)
                        Periode {{ $from ? $monthLabel($from) : 'awal' }} – {{ $to ? $monthLabel($to) : 'terakhir' }}
                    @else
                        Seluruh data produksi
                    @endif
                </div>
            </div>
            <div class="text-right text-[12.5px]" style="color:var(--muted)">
                Data tersedia<br>
                <span style="color:var(--ink-2)">{{ $d($range->min) }} – {{ $d($range->max) }}</span>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mt-5">
            @foreach ([
                ['Jumlah PRO', $n($summary->orders)],
                ['Produk (material)', $n($summary->materials)],
                ['Baik', $n($summary->ok) . ' pcs'],
                ['NC / Reject', $n($summary->nc) . ' / ' . $n($summary->rej)],
                ['Berat baik', number_format(($summary->ok_kg ?? 0) / 1000, 1, ',', '.') . ' ton'],
                ['Dikerjakan di', $n($summary->machines) . ' mesin'],
                ['Produksi', $d($summary->first_date) . ' – ' . $d($summary->last_date)],
            ] as [$l, $v])
                <div>
                    <div class="text-[12px]" style="color:var(--muted)">{{ $l }}</div>
                    <div class="text-[14px] font-medium mt-0.5">{{ $v }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===================== FILTER BULAN ===================== --}}
    <form class="card card-pad !py-3.5 flex flex-wrap items-end gap-3" method="GET">
        <div><label class="label">Dari bulan</label><input type="month" name="from" value="{{ $from }}" class="input w-[160px]"></div>
        <div><label class="label">Sampai bulan</label><input type="month" name="to" value="{{ $to }}" class="input w-[160px]"></div>
        @if ($materialId)<input type="hidden" name="material_id" value="{{ $materialId }}">@endif
        <button class="btn btn-primary">Terapkan</button>
        @if ($filtered || $materialId)
            <a href="{{ route('customers.show', $customer) }}" class="btn">Reset</a>
        @endif
        <div class="ml-auto text-[12px]" style="color:var(--muted)">Kosongkan bulan untuk melihat seluruh riwayat.</div>
    </form>

    @if (! $summary->reports)
        <x-alert type="warn">Tidak ada produksi untuk customer ini pada periode yang dipilih.</x-alert>
    @else
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        {{-- ===================== PRODUK ===================== --}}
        <div class="card xl:col-span-2">
            <div class="card-pad !pb-2">
                <div class="card-title">Produk yang dipesan</div>
                <div class="card-sub">Per material · klik baris untuk melihat PRO material itu saja</div>
            </div>
            <div class="overflow-auto max-h-[420px] px-2 pb-2">
                <table class="tbl">
                    <thead><tr><th>Material</th><th>Ukuran</th><th class="num">PRO</th><th class="num">Baik</th><th class="num">NC</th><th class="num">Reject</th><th>Terakhir</th></tr></thead>
                    <tbody>
                    @foreach ($products as $p)
                        @php $m = $materials->get($p->material_id); @endphp
                        <tr class="cursor-pointer" @if ($materialId == $p->material_id) style="background:var(--surface-2)" @endif
                            onclick="location.href='{{ route('customers.show', [$customer, 'from' => $from, 'to' => $to, 'material_id' => $p->material_id]) }}#riwayat'">
                            <td class="font-mono text-[12.5px]">{{ $p->code ?? 'Tanpa material' }}</td>
                            <td class="text-[12.5px]" style="color:var(--muted)">{{ $m?->size_label ?? '–' }}</td>
                            <td class="num">{{ $n($p->orders) }}</td>
                            <td class="num">{{ $n($p->ok) }}</td>
                            <td class="num">{{ $p->nc ? $n($p->nc) : '–' }}</td>
                            <td class="num">{{ $p->rej ? $n($p->rej) : '–' }}</td>
                            <td>{{ $d($p->last_date) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ===================== PER BULAN ===================== --}}
        <div class="card">
            <div class="card-pad !pb-2">
                <div class="card-title">Rekap per bulan</div>
                <div class="card-sub">Jumlah PRO dan hasil baik</div>
            </div>
            <div class="overflow-auto max-h-[420px] px-2 pb-2">
                <table class="tbl">
                    <thead><tr><th>Bulan</th><th class="num">PRO</th><th class="num">Baik</th><th class="num">NC</th><th class="num">Reject</th></tr></thead>
                    <tbody>
                    @foreach ($months as $mo)
                        <tr>
                            <td>{{ $monthLabel($mo->month) }}</td>
                            <td class="num">{{ $n($mo->orders) }}</td>
                            <td class="num">{{ $n($mo->ok) }}</td>
                            <td class="num">{{ $mo->nc ? $n($mo->nc) : '–' }}</td>
                            <td class="num">{{ $mo->rej ? $n($mo->rej) : '–' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ===================== RIWAYAT PRO ===================== --}}
    <div class="card" id="riwayat">
        <div class="card-pad !pb-2 flex flex-wrap items-start justify-between gap-2">
            <div>
                <div class="card-title">Riwayat PRO</div>
                <div class="card-sub">Terbaru di atas · klik No. PRO untuk detail per shift & mesin</div>
            </div>
            @if ($materialId)
                <a href="{{ route('customers.show', [$customer, 'from' => $from, 'to' => $to]) }}#riwayat" class="chip chip-muted inline-flex items-center gap-1">
                    Material {{ $activeMaterial?->code ?? $materialId }} <x-icon name="x" :size="12" />
                </a>
            @endif
        </div>
        <div class="overflow-x-auto px-2 pb-2">
            <table class="tbl">
                <thead><tr><th>No. PRO</th><th>Material</th><th>Periode produksi</th><th class="num">Laporan</th><th class="num">Mesin</th><th class="num">Baik</th><th class="num">NC</th><th class="num">Reject</th></tr></thead>
                <tbody>
                @forelse ($orders as $o)
                    <tr>
                        <td><a href="{{ route('orders.show', $o->order_no) }}" class="underline underline-offset-2 font-medium">{{ $o->order_no }}</a></td>
                        <td>
                            <div class="font-mono text-[12px]">{{ $o->code ?? '–' }}</div>
                            <div class="text-[11.5px]" style="color:var(--muted)">{{ $materials->get($o->material_id)?->size_label }}</div>
                        </td>
                        <td class="text-[12.5px]">{{ $o->first_date === $o->last_date ? $d($o->first_date) : $d($o->first_date) . ' – ' . $d($o->last_date) }}</td>
                        <td class="num">{{ $n($o->reports) }}</td>
                        <td class="num">{{ $n($o->machines) }}</td>
                        <td class="num">{{ $n($o->ok) }}</td>
                        <td class="num">{{ $o->nc ? $n($o->nc) : '–' }}</td>
                        <td class="num">{{ $o->rej ? $n($o->rej) : '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-8" style="color:var(--muted)">Tidak ada PRO.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3">{{ $orders->fragment('riwayat')->links() }}</div>
    </div>
    @endif

    <a href="{{ url()->previous() }}" class="btn"><x-icon name="arrow-left" :size="16" />Kembali</a>
</div>
</x-layouts.app>
