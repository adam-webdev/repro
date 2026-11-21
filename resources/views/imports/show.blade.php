<x-layouts.app title="Detail Import">
@php
    $groups = $batch->groups ?? [];
    $isDaily = $batch->type === 'daily';
    $replaceCount = $isDaily ? collect($groups)->where('existing', '>', 0)->count() : 0;
@endphp
<div class="space-y-4">
    <div class="card card-pad flex flex-wrap items-center gap-4">
        <div class="min-w-0 flex-1">
            <div class="text-[12px]" style="color:var(--muted)">{{ $isDaily ? 'Laporan harian' : 'Export SAP (GI / GR)' }} · diunggah {{ $batch->created_at->format('d/m/Y H:i') }} oleh <x-user-link :employee="$batch->user?->employee_id" :name="$batch->user?->name ?? 'Sistem (terminal)'" /></div>
            <div class="text-[17px] font-semibold truncate mt-0.5">{{ $batch->file_name }}</div>
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="chip chip-ok">{{ number_format($batch->rows_valid, 0, ',', '.') }} baris valid</span>
            @if ($errorCount)<span class="chip chip-crit">{{ $errorCount }} error (tidak diimport)</span>@endif
            @if ($warningCount)<span class="chip chip-warn">{{ $warningCount }} peringatan</span>@endif
        </div>
    </div>

    @if ($batch->status === 'failed')
        <x-alert type="crit">{{ $batch->message }}</x-alert>
    @endif

    @if ($batch->status === 'preview' && $batch->rows_valid > 0)
        <div class="card card-pad flex flex-wrap items-center gap-3" style="{{ $locked ? 'border-color:var(--st-crit)' : '' }}">
            <div class="flex-1 min-w-[240px] text-[13.5px]">
                @if ($locked)
                    <b style="color:var(--st-crit)">Tidak bisa disimpan oleh akun Anda.</b>
                    <span>Data {{ implode(', ', $locked) }} sudah ada dan lebih lama dari {{ config('production.lock_days') }} hari, jadi penggantiannya perlu Admin Master.</span>
                @elseif ($isDaily)
                    Siap disimpan: <b>{{ count($groups) }}</b> kombinasi tanggal + shift.
                    @if ($replaceCount) <b>{{ $replaceCount }}</b> di antaranya sudah ada dan akan <b>diganti</b> dengan data dari file ini. @endif
                @else
                    {{ number_format($groups['sap']['rows'] ?? 0, 0, ',', '.') }} order SAP ·
                    {{ number_format($groups['sap']['matched'] ?? 0, 0, ',', '.') }} cocok dengan order di data produksi ·
                    {{ number_format($groups['sap']['existing'] ?? 0, 0, ',', '.') }} sudah ada (akan diperbarui).
                @endif
            </div>
            <form method="POST" action="{{ route('imports.cancel', $batch) }}">@csrf<button class="btn">Batal</button></form>
            <form method="POST" action="{{ route('imports.commit', $batch) }}" x-data="{ busy: false }" @submit="busy = true">@csrf
                <button class="btn btn-primary" @disabled($locked) :disabled="busy">
                    <span x-show="!busy">Simpan ke database</span><span x-show="busy" x-cloak>Menyimpan…</span>
                </button>
            </form>
        </div>
    @elseif ($batch->status === 'done')
        <x-alert type="ok">Tersimpan {{ $batch->committed_at?->format('d/m/Y H:i') }}: {{ number_format($batch->rows_imported, 0, ',', '.') }} baris
            @if ($batch->rows_replaced) · {{ number_format($batch->rows_replaced, 0, ',', '.') }} baris lama diganti @endif.
            <a href="{{ route('dashboard') }}" class="underline underline-offset-2 ml-1">Lihat dashboard</a></x-alert>
    @endif

    @if ($isDaily && $groups)
        <div class="card">
            <div class="card-pad !pb-2">
                <div class="card-title">Isi file per tanggal & shift</div>
                <div class="card-sub">Baris yang statusnya "Ganti" akan menggantikan data lama pada tanggal + shift tersebut.</div>
            </div>
            <div class="overflow-x-auto px-2 pb-2 max-h-[480px]">
                <table class="tbl">
                    <thead><tr><th>Tanggal</th><th>Shift</th><th class="num">Baris</th><th class="num">Mesin</th><th class="num">Hasil baik</th><th>Status</th><th>Perubahan</th><th>Data lama</th></tr></thead>
                    @foreach ($groups as $g)
                    @php $diff = $g['diff'] ?? null; @endphp
                    <tbody x-data="{ open: false }">
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($g['date'])->translatedFormat('D, d M Y') }}</td>
                            <td>Shift {{ $g['shift'] }}</td>
                            <td class="num">{{ number_format($g['rows'], 0, ',', '.') }}</td>
                            <td class="num">{{ $g['machines'] }}</td>
                            <td class="num">{{ number_format($g['ok'], 0, ',', '.') }}</td>
                            <td>
                                @if ($batch->status === 'done')<span class="chip chip-ok">Tersimpan</span>
                                @elseif ($g['existing'])<span class="chip chip-warn">Ganti</span>
                                @else<span class="chip chip-ok">Baru</span>@endif
                            </td>
                            <td>
                                @if ($diff)
                                    @php $nChanged = $diff['changed_count'] ?? count($diff['changed']); $nAdded = $diff['added_count'] ?? count($diff['added']); @endphp
                                    @if (! $nChanged && ! $nAdded && ! $diff['removed'])
                                        <span class="chip chip-muted">Isi sama persis</span>
                                    @else
                                        <button type="button" class="flex flex-wrap gap-1" @click="open = !open" title="Lihat rincian">
                                            @if ($nChanged)<span class="chip chip-warn">{{ $nChanged }} berubah</span>@endif
                                            @if ($nAdded)<span class="chip chip-ok">+{{ $nAdded }} baris</span>@endif
                                            @if ($diff['removed'])<span class="chip chip-crit">−{{ $diff['removed'] }} baris</span>@endif
                                            <span class="chip chip-muted">{{ $diff['same'] }} sama</span>
                                            <span class="text-[12px] underline" style="color:var(--ink-2)" x-text="open ? 'tutup' : 'rincian'"></span>
                                        </button>
                                    @endif
                                @else
                                    <span style="color:var(--muted)">–</span>
                                @endif
                            </td>
                            <td class="text-[12.5px]" style="color:var(--muted)">
                                @if ($g['existing'] && $batch->status !== 'done')
                                    {{ $g['existing'] }} baris
                                    @if ($g['existing_by']) · {{ $g['existing_by'] }} @endif
                                    @if ($g['existing_at']) · {{ \Carbon\Carbon::parse($g['existing_at'])->format('d/m H:i') }} @endif
                                @else – @endif
                            </td>
                        </tr>
                        @if ($diff && ($diff['changed'] || $diff['added'] || $diff['removed']))
                            <tr x-show="open" x-cloak>
                                <td colspan="8" class="!whitespace-normal" style="background:var(--surface-2)">
                                    <div class="space-y-1.5 text-[12.5px] py-1">
                                        @foreach ($diff['changed'] as $c)
                                            <div><b>Baris Excel {{ $c['row'] }}</b> · {{ $c['machine'] }} · order {{ $c['order'] }}:
                                                @foreach ($c['fields'] as [$f, $old, $new])
                                                    <span class="inline-block mr-2">{{ $f }} <s style="color:var(--muted)">{{ $old }}</s> → <b>{{ $new }}</b></span>
                                                @endforeach
                                            </div>
                                        @endforeach
                                        @foreach ($diff['added'] as $a)
                                            <div style="color:var(--good)"><b>Baris Excel {{ $a['row'] }}</b> · {{ $a['machine'] }} · order {{ $a['order'] }}: baris baru</div>
                                        @endforeach
                                        @foreach ($diff['removed_rows'] ?? [] as $r)
                                            <div style="color:var(--bad)">Hilang dari file baru: {{ $r['machine'] }} · order {{ $r['order'] }} · mulai {{ $r['start'] }}</div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    @if ($issues->count())
        <div class="card">
            <div class="card-pad !pb-2">
                <div class="card-title">Error & peringatan</div>
                <div class="card-sub">Baris error tidak ikut diimport. Peringatan tetap diimport tapi sebaiknya dicek.</div>
            </div>
            <div class="overflow-x-auto px-2 pb-2 max-h-[420px]">
                <table class="tbl">
                    <thead><tr><th>Level</th><th>Sheet</th><th class="num">Baris Excel</th><th>Keterangan</th></tr></thead>
                    <tbody>
                    @foreach ($issues as $e)
                        <tr>
                            <td><span class="chip {{ $e->level === 'error' ? 'chip-crit' : 'chip-warn' }}">{{ $e->level === 'error' ? 'Error' : 'Peringatan' }}</span></td>
                            <td>{{ $e->sheet ?? '-' }}</td>
                            <td class="num">{{ $e->row_no ?? '-' }}</td>
                            <td class="!whitespace-normal">{{ $e->message }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <a href="{{ route('imports.index') }}" class="btn"><x-icon name="arrow-left" :size="16" />Kembali ke Import</a>
</div>
</x-layouts.app>
