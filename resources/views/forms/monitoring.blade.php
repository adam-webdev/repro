<x-layouts.app title="Monitoring Karu">
{{-- Monitoring Daily: dari Form Rekapan karu (termasuk planning) — App\Services\MonitoringDailyService. --}}
<div x-data="monitoringDaily(@js([
        'filters' => $filters,
        'endpoint' => route('forms.monitoring.data'),
        'today' => now()->toDateString(),
    ]))" x-init="init()" class="space-y-3">

    {{-- ===================== FILTER ===================== --}}
    <div class="card card-pad !py-3 flex flex-wrap items-end gap-x-3 gap-y-2">
        <div>
            <label class="label">Grup mesin</label>
            <select class="input w-[150px]" x-model="f.group" @change="load()">
                <option value="">Semua mesin</option>
                @foreach ($groups as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label">Tanggal</label>
            <input type="date" class="input w-[150px]" x-model="f.date" @change="load()">
        </div>
        <div>
            <label class="label">Shift</label>
            <select class="input w-[110px]" x-model="f.shift" @change="load()">
                <option value="">Semua</option>
                @foreach (config('production.karu_shifts') as $s => $time)<option value="{{ $s }}">Shift {{ $s }} ({{ $time['start'] }}–{{ $time['end'] }})</option>@endforeach
            </select>
        </div>
        <button class="btn" @click="reset()" x-show="isFiltered()" x-cloak>Reset</button>
        <div class="ml-auto text-[12px]" style="color:var(--muted)">
            <span x-show="loading">Memuat…</span>
            <span x-show="!loading">Sumber: Form Rekapan karu</span>
        </div>
    </div>

    <div class="md-board space-y-3">
        {{-- ===================== BARIS 1: KPI & produksi 1 bulan ===================== --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="md-tile">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="md-pill gold" x-text="d ? d.group_label.toUpperCase() : '—'"></span>
                    <span class="md-pill date"><x-icon name="calendar-days" :size="15" /><span x-text="shortDate(f.date)"></span></span>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-3">
                    <template x-for="k in kpis()" :key="k.key">
                        <div>
                            <div class="md-kpi"><div class="md-kpi-val" x-text="d ? k.value : '—'"></div></div>
                            <div class="flex items-center justify-between gap-1 mt-1 px-0.5">
                                <span class="flex items-center gap-0.5 min-w-0">
                                    <span class="md-kpi-label" x-text="k.label"></span>
                                    <x-info-tip dark :title="'Rumus'"><div x-html="k.info"></div><div class="info-src">Sumber: Form Rekapan karu pada tanggal &amp; shift yang sedang difilter (grup mesin bila dipilih). ▲▼ = perubahan dibanding hari sebelumnya.</div></x-info-tip>
                                </span>
                                <span class="text-[11px] font-bold text-right leading-tight" x-show="d">
                                    <template x-if="k.change !== null">
                                        <span :class="k.change >= 0 ? 'md-up' : 'md-down'" x-text="(k.change >= 0 ? '▲' : '▼') + num(Math.abs(k.change), 1) + '%'"></span>
                                    </template>
                                    <span class="block text-[9.5px] font-semibold" style="color:var(--md-muted)" x-text="k.sub"></span>
                                </span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="md-tile">
                <div class="flex items-start justify-between gap-2">
                    <span class="flex items-center gap-1 min-w-0">
                        <span class="md-pill" x-text="'Produksi ' + groupShort() + ' ' + (d?.month.label || '')"></span>
                        <x-info-tip dark>
                            <ul>
                                <li><b>Garis</b> = total pcs per tanggal (Hasil OK + Reject + Hasil NC)</li>
                                <li><b>Angka besar</b> = total pcs satu bulan</li>
                                <li><b>▲ hijau</b> = total pcs ≥ target harian, <b>▼ merah</b> = di bawah target</li>
                                <li><b>Target harian</b> = jumlah per mesin: Qty planning; bila mesin tidak punya planning → target harian mesin (master mesin); bila kosong juga → Std output × Waktu kerja</li>
                            </ul>
                            <div class="info-src">Sumber: Form Rekapan karu satu bulan penuh dari tanggal yang difilter (shift &amp; grup mesin mengikuti filter).</div>
                        </x-info-tip>
                    </span>
                    <div class="md-big" x-text="d ? num(d.month.total_pcs) : '—'"></div>
                </div>
                <div id="ch-md-month" class="mt-1 min-h-[200px]"></div>
            </div>
        </div>

        {{-- ===================== BARIS 2: produksi, actual / planning, NG per mesin ===================== --}}
        <div class="grid grid-cols-1 xl:grid-cols-12 gap-3">
            <div class="md-tile xl:col-span-5">
                <div class="flex items-start justify-between gap-2">
                    <span class="flex items-center gap-1 min-w-0">
                        <span class="md-pill">Total produksi pcs</span>
                        <x-info-tip dark>
                            <ul>
                                <li><b>Batang · Total pcs</b> = Hasil OK + Reject + Hasil NC per mesin</li>
                                <li><b>Total waktu</b> = Cutting time + Downtime + Set up (menit)</li>
                                <li><b>Cutting time %</b> = Cutting time ÷ Total waktu × 100</li>
                                <li><b>ET &amp; SU rate %</b> = (Downtime + Set up) ÷ Total waktu × 100. ET = downtime, SU = set up; makin kecil makin bagus.</li>
                                <li><b>Angka besar</b> = total pcs semua mesin</li>
                            </ul>
                            <div class="info-src">Sumber: Form Rekapan karu pada tanggal &amp; shift yang sedang difilter (grup mesin bila dipilih) — isian Hasil OK / Reject / NC, Cutting time, menit Downtime &amp; Set Up. Menit Bongkar tidak dihitung.</div>
                        </x-info-tip>
                    </span>
                    <div class="md-big" style="color:var(--md-teal)" x-text="d ? num(d.kpi.total_pc) : '—'"></div>
                </div>
                <div id="ch-md-production" class="mt-1 min-h-[300px]"></div>
            </div>
            <div class="md-tile xl:col-span-4">
                <span class="flex items-center gap-1 min-w-0">
                    <span class="md-pill">Actual / Planning &amp; efficiency</span>
                    <x-info-tip dark>
                        <ul>
                            <li><b>Actual</b> (batang) = Hasil OK + Reject + Hasil NC (pcs)</li>
                            <li><b>Planning</b> (batang) = jumlah Qty planning semua PRO di mesin itu (pcs)</li>
                            <li><b>Productivity %</b> (di tooltip) = Actual ÷ Planning × 100</li>
                            <li><b>Std pcs</b> = Std output (pcs/jam) × Waktu kerja (jam)</li>
                            <li><b>Efficiency %</b> (garis) = Actual ÷ Std pcs × 100. Titik hijau = mencapai target efisiensi mesin (default {{ (int) config('production.monitoring.target_efficiency', 85) }}%), merah = di bawah target.</li>
                        </ul>
                        <div class="info-src">Sumber: Form Rekapan karu pada tanggal &amp; shift yang sedang difilter (grup mesin bila dipilih) — isian Qty planning, Std output, Waktu kerja, Hasil OK / Reject / NC. PRO tanpa Std output tidak ikut dihitung di Efficiency.</div>
                    </x-info-tip>
                </span>
                <div id="ch-md-eff" class="mt-1 min-h-[300px]"></div>
            </div>
            <div class="md-tile xl:col-span-3">
                <span class="flex items-center gap-1 min-w-0">
                    <span class="md-pill">NG tertinggi</span>
                    <x-info-tip dark>
                        <ul>
                            <li><b>NG rate %</b> = Hasil NC ÷ Total pcs (OK + Reject + NC) × 100, per mesin</li>
                            <li>Diurutkan dari NG rate terbesar, maksimal 8 mesin; mesin tanpa NC tidak ditampilkan</li>
                        </ul>
                        <div class="info-src">Sumber: isian <b>Hasil NC</b> di Form Rekapan karu pada tanggal &amp; shift yang sedang difilter (grup mesin bila dipilih).</div>
                    </x-info-tip>
                </span>
                <div id="ch-md-ng" class="mt-1 min-h-[300px]"></div>
            </div>
        </div>

        {{-- ===================== BARIS 3: set up & downtime per tanggal ===================== --}}
        <div class="md-tile">
            <span class="flex items-center gap-1 min-w-0">
                <span class="md-pill" x-text="'Set up & downtime ' + groupShort() + ' ' + (d?.month.label || '')"></span>
                <x-info-tip dark>
                    <ul>
                        <li><b>Batang kuning</b> = jumlah menit Set Up per tanggal</li>
                        <li><b>Batang merah</b> = jumlah menit Downtime per tanggal</li>
                    </ul>
                    <div class="info-src">Sumber: menit Set Up &amp; Downtime yang diisi karu di Form Rekapan, satu bulan penuh dari tanggal yang difilter. Menit Bongkar tidak dihitung.</div>
                </x-info-tip>
            </span>
            <div id="ch-md-loss" class="mt-1 min-h-[200px]"></div>
        </div>

        {{-- ===================== BARIS 4: kartu per mesin ===================== --}}
        <div class="flex items-center gap-1" x-show="d?.machines.length">
            <span class="md-pill">Rincian per mesin</span>
            <x-info-tip dark>
                <ul>
                    <li><b>Efficiency</b> = Total pcs ÷ Std pcs × 100; Std pcs = Std output (pcs/jam) × Waktu kerja (jam). Hijau = mencapai target efisiensi mesin, merah = di bawah.</li>
                    <li><b>Productivity</b> = Total pcs ÷ Qty planning × 100</li>
                    <li><b>Total waktu</b> = Cutting time + Downtime + Set up (menit)</li>
                    <li><b>Cutting time (Eff.)</b> = menit cutting dan % terhadap total waktu</li>
                    <li><b>Baris downtime / set up</b>: MNT = jumlah menit, FREQ = berapa kali diisi, % = menit ÷ total waktu</li>
                    <li><b>Total downtime</b> = menit Downtime + Set up dan % terhadap total waktu</li>
                </ul>
                <div class="info-src">Sumber: Form Rekapan karu pada tanggal &amp; shift yang sedang difilter (grup mesin bila dipilih), per mesin (semua PRO digabung).</div>
            </x-info-tip>
        </div>
        <div class="flex flex-wrap gap-3 items-start">
            <template x-for="m in (d?.machines || [])" :key="m.machine_id">
                <div class="md-card">
                    <table>
                        <thead>
                            <tr><th x-text="m.code"></th><th>MNT</th><th>FREQ</th><th>%</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="4" class="md-boxes">
                                    <div class="md-box-row">
                                        <div class="md-box">
                                            <div class="md-box-label">Efficiency</div>
                                            <div class="md-box-val" :class="m.efficiency === null ? '' : (m.efficiency >= m.target_efficiency ? 'good' : 'bad')"
                                                 x-text="m.efficiency === null ? '-' : num(m.efficiency, 0) + '%'"></div>
                                            <div class="md-box-sub" x-text="m.efficiency === null ? 'std output kosong' : 'target ' + num(m.target_efficiency, 0) + '%'"></div>
                                        </div>
                                        <div class="md-box">
                                            <div class="md-box-label">Productivity</div>
                                            <div class="md-box-val" x-text="m.productivity === null ? '-' : num(m.productivity, 0) + '%'"></div>
                                            <div class="md-box-sub" x-text="m.planned_qty ? num(m.pcs) + ' / ' + num(m.planned_qty) + ' pcs' : 'belum ada planning'"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr x-show="m.planned_qty || m.planned_start">
                                <td colspan="4" class="md-plan !text-left !normal-case"
                                    x-text="'Planning: ' + (m.planned_start ? 'start ' + m.planned_start : '') + (m.planned_qty ? ' · ' + num(m.planned_qty) + ' pcs' : '') + ' · aktual ' + num(m.pcs) + ' pcs'"></td>
                            </tr>
                            <tr class="md-cut">
                                <td>Cutting time (Eff.)</td><td x-text="num(m.table.cutting.minutes)"></td><td></td><td x-text="m.table.cutting.pct + '%'"></td>
                            </tr>
                            <template x-for="r in m.table.downtime" :key="'dt' + r.name">
                                <tr><td x-text="r.name"></td><td x-text="num(r.minutes)"></td><td x-text="num(r.freq)"></td><td x-text="r.pct + '%'"></td></tr>
                            </template>
                            <tr class="md-sub"><td colspan="4" class="!text-center">SET UP</td></tr>
                            <template x-for="r in m.table.setup" :key="'su' + r.name">
                                <tr><td x-text="r.name"></td><td x-text="num(r.minutes)"></td><td x-text="num(r.freq)"></td><td x-text="r.pct + '%'"></td></tr>
                            </template>
                            <tr class="md-total">
                                <td class="!text-right">Total downtime</td><td x-text="num(m.table.total.minutes)"></td><td></td><td x-text="m.table.total.pct + '%'"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
            <div class="md-tile w-full text-center text-[13px]" style="color:var(--md-muted)" x-show="d && !d.machines.length">
                Belum ada Form Rekapan karu untuk tanggal & grup ini.
            </div>
        </div>
    </div>
</div>
</x-layouts.app>
