<x-layouts.app title="Tabel Data">
<div x-data="reportDetail()" class="space-y-4">
    <form class="card card-pad !py-3.5 flex flex-wrap items-end gap-3" method="GET">
        <div><label class="label">Dari</label><input type="date" name="from" value="{{ $from }}" class="input w-[140px]"></div>
        <div><label class="label">Sampai</label><input type="date" name="to" value="{{ $to }}" class="input w-[140px]"></div>
        <div>
            <label class="label">Shift</label>
            <select name="shift" class="input w-[100px]">
                <option value="">Semua</option>
                @foreach ([1, 2, 3] as $s)<option value="{{ $s }}" @selected(request('shift') == $s)>Shift {{ $s }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label">Area</label>
            <select name="area_id" class="input w-[110px]">
                <option value="">Semua</option>
                @foreach ($areas as $a)<option value="{{ $a->id }}" @selected(request('area_id') == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label">Mesin</label>
            <select name="machine_id" class="input w-[120px]">
                <option value="">Semua</option>
                @foreach ($machines as $m)<option value="{{ $m->id }}" @selected(request('machine_id') == $m->id)>{{ $m->code }}</option>@endforeach
            </select>
        </div>
        <div class="flex-1 min-w-[180px]"><label class="label">Cari</label><input name="q" value="{{ request('q') }}" placeholder="No. order, material, customer" class="input w-full"></div>
        <button class="btn btn-primary">Terapkan</button>
        <a href="{{ route('reports.export', request()->query()) }}" class="btn">
            <x-icon name="download" :size="16" />
            Export Excel
        </a>
    </form>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        @foreach ([
            ['Laporan', $totals->c, ''], ['Baik', $totals->ok, 'pcs'], ['NC', $totals->nc, 'pcs'],
            ['Reject', $totals->rej, 'pcs'], ['Downtime', round(($totals->dt ?? 0) / 60), 'jam'],
        ] as [$l, $v, $u])
            <div class="card card-pad !py-3">
                <div class="text-[12px]" style="color:var(--muted)">{{ $l }}</div>
                <div class="text-[19px] font-semibold mt-0.5">{{ number_format($v ?? 0, 0, ',', '.') }} <span class="text-[12px] font-normal" style="color:var(--muted)">{{ $u }}</span></div>
            </div>
        @endforeach
    </div>

    @if ($from === $to && request()->filled('shift') && ! request()->hasAny(['area_id', 'machine_id', 'q']) && $totals->c > 0)
        {{-- Hapus seluruh data satu tanggal + shift --}}
        <div class="card card-pad !py-3 flex flex-wrap items-center gap-3" x-data="{ open: false }">
            <div class="flex-1 min-w-[220px] text-[13px]" style="color:var(--ink-2)">
                Menampilkan semua data <b>{{ \Carbon\Carbon::parse($from)->translatedFormat('d M Y') }} · Shift {{ request('shift') }}</b>.
                Untuk memperbaiki, cukup import ulang file yang benar (data otomatis diganti). Hapus hanya jika data ini memang tidak seharusnya ada.
            </div>
            <button type="button" class="btn btn-danger" @click="open = !open">Hapus data tanggal & shift ini</button>
            <form x-show="open" x-cloak method="POST" action="{{ route('imports.destroy-group') }}" class="w-full flex flex-wrap items-end gap-2 pt-2 border-t" style="border-color:var(--border)"
                  @submit="if (!confirm('Hapus {{ $totals->c }} baris data {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} shift {{ request('shift') }}?')) $event.preventDefault()">
                @csrf
                <input type="hidden" name="date" value="{{ $from }}">
                <input type="hidden" name="shift" value="{{ request('shift') }}">
                <label class="block flex-1 min-w-[240px]"><span class="label">Alasan penghapusan (wajib, tercatat di log)</span>
                    <input name="reason" value="{{ old('reason') }}" required minlength="5" maxlength="200" class="input w-full" placeholder="mis. salah tanggal, seharusnya 15/11"></label>
                <button class="btn btn-danger">Hapus {{ number_format($totals->c, 0, ',', '.') }} baris</button>
            </form>
        </div>
    @endif

    <div class="card">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr>
                    <th>Tanggal</th><th>Shift</th><th>Mesin</th><th>Order</th><th>Customer</th><th>Material / size</th><th>Jam</th>
                    <th class="num">Baik</th><th class="num">NC</th><th class="num">Reject</th><th class="num">Jam kerja</th><th class="num">Downtime</th><th class="num">Cutting time</th><th class="num">Cutting %</th><th></th>
                </tr></thead>
                <tbody>
                @forelse ($reports as $r)
                    @php $ct = $r->working_minutes ? $r->cutting_minutes / $r->working_minutes * 100 : null; @endphp
                    <tr>
                        <td>{{ $r->production_date->format('d/m/Y') }}</td>
                        <td><span class="chip chip-muted">S{{ $r->shift }}</span></td>
                        <td class="font-medium">{{ $r->machine?->code }}</td>
                        <td><a href="{{ route('orders.show', $r->order_no) }}" class="underline underline-offset-2">{{ $r->order_no }}</a></td>
                        <td class="max-w-[180px] truncate">@if ($r->customer)<a href="{{ route('customers.show', $r->customer_id) }}" class="underline underline-offset-2">{{ $r->customer->name }}</a>@else-@endif</td>
                        <td>
                            <div class="font-mono text-[12px]">{{ $r->material?->code ?? '-' }}</div>
                            <div class="text-[11.5px]" style="color:var(--muted)">{{ $r->material?->size_label }}</div>
                        </td>
                        <td class="text-[12.5px]">{{ substr($r->start_time, 0, 5) }}–{{ substr($r->end_time, 0, 5) }}</td>
                        <td class="num">{{ number_format($r->ok_qty, 0, ',', '.') }}</td>
                        <td class="num">{{ $r->nc_qty ? number_format($r->nc_qty, 0, ',', '.') : '–' }}</td>
                        <td class="num">{{ $r->reject_qty ? number_format($r->reject_qty, 0, ',', '.') : '–' }}</td>
                        <td class="num">{{ $r->working_minutes }}′</td>
                        <td class="num">{{ $r->downtime_minutes }}′</td>
                        <td class="num">{{ $r->cutting_minutes !== null ? $r->cutting_minutes.'′' : '–' }}</td>
                        <td class="num">
                            @if ($ct !== null)
                                <span class="chip {{ $ct >= 85 ? 'chip-ok' : ($ct >= 75 ? 'chip-warn' : 'chip-crit') }}">{{ number_format($ct, 0) }}%</span>
                            @else – @endif
                        </td>
                        <td class="num"><button type="button" class="btn !h-7 !px-2.5 !text-[12px]" @click="show('{{ route('reports.show', $r) }}')">Rincian</button></td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="text-center !py-10" style="color:var(--muted)">Tidak ada data untuk filter ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3">{{ $reports->links() }}</div>
    </div>

    {{-- Panel rincian --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex justify-end" @keydown.escape.window="open=false">
        <div class="absolute inset-0 bg-black/40" @click="open=false"></div>
        <div class="relative w-full max-w-md h-full overflow-y-auto p-5 space-y-4" style="background:var(--surface)">
            <div class="flex items-center justify-between">
                <div class="text-[16px] font-semibold">Rincian laporan</div>
                <button class="icon-btn" @click="open=false" aria-label="Tutup"><x-icon name="x" :size="17" /></button>
            </div>
            <div x-show="loading" class="space-y-2"><div class="skeleton h-5"></div><div class="skeleton h-24"></div><div class="skeleton h-24"></div></div>
            <template x-if="r">
                <div class="space-y-4 text-[13.5px]">
                    <div class="card card-pad !py-3">
                        <div><b x-text="r.machine"></b> · <span x-text="r.date"></span> · Shift <span x-text="r.shift"></span></div>
                        <div class="mt-1" style="color:var(--ink-2)">Order <b x-text="r.order"></b> · <template x-if="r.customer_url"><a :href="r.customer_url" class="underline underline-offset-2" x-text="r.customer"></a></template><span x-show="!r.customer_url">-</span></div>
                        <div class="font-mono text-[12px] mt-1" x-text="r.material"></div>
                        <div class="text-[12px]" style="color:var(--muted)" x-text="r.size"></div>
                    </div>
                    <div>
                        <div class="card-title mb-1.5">Downtime</div>
                        <template x-for="d in r.downtimes"><div class="flex justify-between py-1.5 border-b" style="border-color:var(--border)">
                            <span><span x-text="d.name"></span> <span class="text-[11.5px]" style="color:var(--muted)" x-text="'· ' + d.category"></span></span>
                            <span class="tabular-nums"><span x-text="d.minutes"></span>′<span x-show="d.frequency" x-text="' · ' + d.frequency + 'x'"></span></span>
                        </div></template>
                        <div x-show="!r.downtimes.length" style="color:var(--muted)">Tidak ada downtime.</div>
                        <div x-show="r.downtime_note" class="mt-2 text-[12.5px]" style="color:var(--ink-2)">Catatan: <span x-text="r.downtime_note"></span></div>
                    </div>
                    <div>
                        <div class="card-title mb-1.5">Rincian reject</div>
                        <template x-for="d in r.defects"><div class="flex justify-between py-1.5 border-b" style="border-color:var(--border)">
                            <span x-text="d.name"></span><span class="tabular-nums" x-text="fmt(d.qty) + ' pcs'"></span>
                        </div></template>
                        <div x-show="!r.defects.length" style="color:var(--muted)">Tidak ada reject.</div>
                    </div>
                    <div class="card card-pad !py-3 grid grid-cols-2 gap-3 text-[12.5px]">
                        <div>
                            <div style="color:var(--muted)">Pertama diinput</div>
                            <button type="button" class="font-medium hover:underline text-left" :disabled="!r.created_card" @click="r.created_card && $dispatch('employee-card', r.created_card)" x-text="r.created_by"></button>
                            <div style="color:var(--muted)" x-text="r.created_at"></div>
                        </div>
                        <div>
                            <div style="color:var(--muted)">Terakhir diperbarui</div>
                            <button type="button" class="font-medium hover:underline text-left" :disabled="!r.updated_card" @click="r.updated_card && $dispatch('employee-card', r.updated_card)" x-text="r.updated_by"></button>
                            <div style="color:var(--muted)" x-text="r.updated_at"></div>
                        </div>
                        <div class="col-span-2" style="color:var(--muted)">File: <span x-text="r.file || '-'"></span></div>
                    </div>
                    <div x-show="r.history.length">
                        <div class="card-title mb-1.5">Riwayat data tanggal & shift ini</div>
                        <template x-for="h in r.history">
                            <div class="py-1.5 border-b text-[12.5px]" style="border-color:var(--border)">
                                <div class="flex justify-between gap-2">
                                    <span><b x-text="h.action"></b> oleh
                                        <button type="button" class="font-medium hover:underline" @click="h.card && $dispatch('employee-card', h.card)" x-text="h.who"></button>
                                        <span style="color:var(--muted)" x-text="'· ' + h.role"></span></span>
                                    <span style="color:var(--muted)" x-text="h.at"></span>
                                </div>
                                <div style="color:var(--muted)" x-text="h.info"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
</x-layouts.app>
