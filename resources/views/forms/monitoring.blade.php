<x-layouts.app title="Monitoring Karu">
{{-- Monitoring Daily: dari Form Produksi & Form Evaluasi (planning) karu — App\Services\MonitoringDailyService. --}}
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
            <span x-show="!loading">Sumber: Form Produksi & Form Evaluasi karu</span>
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
                                <span class="md-kpi-label" x-text="k.label"></span>
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
                    <span class="md-pill" x-text="'Produksi ' + groupShort() + ' ' + (d?.month.label || '')"></span>
                    <div class="md-big" x-text="d ? num(d.month.total_pcs) : '—'"></div>
                </div>
                <div id="ch-md-month" class="mt-1 min-h-[200px]"></div>
            </div>
        </div>

        {{-- ===================== BARIS 2: produksi, actual / planning, NG per mesin ===================== --}}
        <div class="grid grid-cols-1 xl:grid-cols-12 gap-3">
            <div class="md-tile xl:col-span-5">
                <div class="flex items-start justify-between gap-2">
                    <span class="md-pill">Total produksi pcs</span>
                    <div class="md-big" style="color:var(--md-teal)" x-text="d ? num(d.kpi.total_pc) : '—'"></div>
                </div>
                <div id="ch-md-production" class="mt-1 min-h-[300px]"></div>
            </div>
            <div class="md-tile xl:col-span-4">
                <span class="md-pill">Actual / Planning &amp; efficiency</span>
                <div id="ch-md-eff" class="mt-1 min-h-[300px]"></div>
            </div>
            <div class="md-tile xl:col-span-3">
                <span class="md-pill">NG tertinggi</span>
                <div id="ch-md-ng" class="mt-1 min-h-[300px]"></div>
            </div>
        </div>

        {{-- ===================== BARIS 3: set up & downtime per tanggal ===================== --}}
        <div class="md-tile">
            <span class="md-pill" x-text="'Set up & downtime ' + groupShort() + ' ' + (d?.month.label || '')"></span>
            <div id="ch-md-loss" class="mt-1 min-h-[200px]"></div>
        </div>

        {{-- ===================== BARIS 4: tabel downtime per mesin ===================== --}}
        <div class="flex flex-wrap gap-3 items-start">
            <template x-for="m in (d?.machines || [])" :key="m.machine_id">
                <div class="md-card">
                    <table>
                        <thead>
                            <tr><th x-text="m.code"></th><th>MNT</th><th>FREQ</th><th>%</th></tr>
                        </thead>
                        <tbody>
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
                Belum ada Form Produksi / Form Evaluasi karu untuk tanggal & grup ini.
            </div>
        </div>
    </div>
</div>
</x-layouts.app>
