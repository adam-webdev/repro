<x-layouts.app title="Import Data">
<div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
    {{-- Upload --}}
    <div class="xl:col-span-2 space-y-4">
        <div class="card card-pad">
            <div class="card-title">Upload file Excel</div>
            <div class="card-sub">Laporan harian (sheet hasil + DT) per shift / per hari, atau export SAP (Order · GI · GR).</div>

            <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="mt-4"
                  x-data="{ name: '', drag: false, busy: false }" @submit="busy = true">
                @csrf
                <label class="block rounded-2xl border-2 border-dashed px-4 py-9 text-center cursor-pointer transition"
                       :style="drag ? 'border-color:var(--ink-2);background:var(--hover)' : 'border-color:var(--border-strong)'"
                       @dragover.prevent="drag=true" @dragleave.prevent="drag=false"
                       @drop.prevent="drag=false; $refs.file.files = $event.dataTransfer.files; name = $refs.file.files[0]?.name || ''">
                    <x-icon name="file-spreadsheet" :size="28" :stroke="1.5" class="mx-auto mb-2" style="color:var(--muted)" />
                    <div class="text-[14px] font-medium" x-text="name || 'Pilih atau tarik file .xlsx ke sini'"></div>
                    <div class="text-[12px] mt-1" style="color:var(--muted)">Maksimal 50 MB · ikuti template di bawah</div>
                    <input x-ref="file" type="file" name="file" accept=".xlsx" class="hidden" @change="name = $event.target.files[0]?.name || ''">
                </label>
                @error('file')<p class="text-[13px] mt-2" style="color:var(--bad)">{{ $message }}</p>@enderror
                <button class="btn btn-primary w-full mt-3 !h-10" :disabled="!name || busy">
                    <span x-show="!busy">Cek file (preview)</span>
                    <span x-show="busy" x-cloak>Membaca file… mohon tunggu</span>
                </button>
            </form>
        </div>

        <div class="card card-pad">
            <div class="card-title">Template import</div>
            <div class="card-sub">Pakai template ini supaya kolomnya pasti terbaca. Di dalamnya ada sheet Petunjuk, Master Mesin, dan Daftar Kolom.</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                <a href="{{ route('imports.template') }}" class="btn !h-10">
                    <x-icon name="download" :size="16" />
                    Template kosong
                </a>
                <a href="{{ route('imports.template', ['contoh' => 1]) }}" class="btn !h-10">
                    <x-icon name="download" :size="16" />
                    Template + contoh isi
                </a>
            </div>
            <p class="text-[12px] mt-2" style="color:var(--muted)">"Contoh isi" berisi 3 baris (tanggal hari ini, shift 1) untuk uji coba upload. Hapus barisnya sebelum dipakai untuk data asli.</p>
        </div>

        <div class="card card-pad text-[13px] leading-relaxed" style="color:var(--ink-2)">
            <div class="card-title mb-2">Cara kerja import</div>
            <ol class="list-decimal pl-5 space-y-1">
                <li>File dibaca dan dicek dulu. Belum ada data yang disimpan.</li>
                <li>Sistem menampilkan tanggal + shift yang ada di file, serta data lama yang akan <b>diganti</b>.</li>
                <li>Klik <b>Simpan</b>. Data tanggal + shift yang sama selalu diganti, <b>tidak pernah dobel</b>.</li>
                <li>Bisa <b>back date</b>: tanggal yang terlewat tinggal diimport kapan saja.</li>
                <li>Mengganti data yang lebih lama dari {{ config('production.lock_days') }} hari hanya bisa oleh Admin Master.</li>
            </ol>
        </div>
    </div>

    {{-- Kalender kelengkapan --}}
    <div class="xl:col-span-3 card card-pad">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="card-title">Kelengkapan data · {{ $month->translatedFormat('F Y') }}</div>
                <div class="card-sub">Angka = jumlah baris laporan per shift. Kosong = belum diimport.</div>
            </div>
            <form class="flex items-center gap-2">
                <a class="btn !h-8 !px-2.5" href="{{ route('imports.index', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}">‹</a>
                <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="input !h-8" onchange="this.form.submit()">
                <a class="btn !h-8 !px-2.5" href="{{ route('imports.index', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}">›</a>
            </form>
        </div>
        @php
            $start = $month->copy()->startOfMonth();
            $lead = ($start->dayOfWeekIso - 1);
            $daysInMonth = $month->daysInMonth;
        @endphp
        <div class="grid grid-cols-7 gap-1.5 mt-4 text-[11.5px]">
            @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $dn)
                <div class="text-center font-medium pb-1" style="color:var(--muted)">{{ $dn }}</div>
            @endforeach
            @for ($i = 0; $i < $lead; $i++)<div></div>@endfor
            @for ($day = 1; $day <= $daysInMonth; $day++)
                @php
                    $date = $start->copy()->day($day);
                    $c = $counts[$date->toDateString()] ?? [];
                    $future = $date->isFuture();
                @endphp
                <div class="rounded-xl border p-1.5 sm:p-2 min-h-[74px]" style="border-color:var(--border);{{ $future ? 'opacity:.4' : '' }}">
                    <div class="font-semibold text-[12px] mb-1 {{ $date->isToday() ? 'underline' : '' }}">{{ $day }}</div>
                    @foreach ([1, 2, 3] as $s)
                        <div class="flex items-center justify-between gap-1 leading-[1.5]">
                            <span style="color:var(--muted)">S{{ $s }}</span>
                            @if (isset($c[$s]))
                                <a href="{{ route('reports.index', ['from' => $date->toDateString(), 'to' => $date->toDateString(), 'shift' => $s]) }}"
                                   class="font-semibold tabular-nums hover:underline" style="color:var(--ink)">{{ $c[$s] }}</a>
                            @elseif (! $future)
                                <span style="color:var(--muted)">–</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endfor
        </div>
    </div>
</div>

{{-- Riwayat --}}
<div class="card mt-4">
    <div class="card-pad !pb-2">
        <div class="card-title">Riwayat import</div>
        <div class="card-sub">Setiap import tercatat: siapa, kapan, file apa, berapa baris.</div>
    </div>
    <div class="overflow-x-auto px-2 pb-2">
        <table class="tbl">
            <thead><tr><th>Waktu</th><th>File</th><th>Jenis</th><th>Oleh</th><th class="num">Baris</th><th class="num">Diganti</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($batches as $b)
                <tr>
                    <td>{{ ($b->committed_at ?? $b->created_at)->format('d/m/Y H:i') }}</td>
                    <td class="max-w-[260px] truncate">{{ $b->file_name }}</td>
                    <td>{{ $b->type === 'sap' ? 'SAP (GI/GR)' : 'Laporan harian' }}</td>
                    <td><x-user-link :employee="$b->user?->employee_id" :name="$b->user?->name ?? 'Sistem (terminal)'" /></td>
                    <td class="num">{{ number_format($b->status === 'done' ? $b->rows_imported : $b->rows_valid, 0, ',', '.') }}</td>
                    <td class="num">{{ $b->rows_replaced ? number_format($b->rows_replaced, 0, ',', '.') : '–' }}</td>
                    <td>
                        @php $chip = ['done' => ['chip-ok', 'Tersimpan'], 'preview' => ['chip-warn', 'Menunggu simpan'], 'cancelled' => ['chip-muted', 'Dibatalkan'], 'failed' => ['chip-crit', 'Gagal']][$b->status] ?? ['chip-muted', $b->status]; @endphp
                        <span class="chip {{ $chip[0] }}">{{ $chip[1] }}</span>
                    </td>
                    <td class="num"><a href="{{ route('imports.show', $b) }}" class="underline underline-offset-2 text-[12.5px]">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center !py-8" style="color:var(--muted)">Belum ada import.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-4 pb-3">{{ $batches->links() }}</div>
</div>
</x-layouts.app>
