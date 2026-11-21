<x-layouts.app title="Dashboard">
<div x-data="dashboard(@js([
        'filters' => $filters,
        'endpoint' => route('dashboard.data'),
        'canOpenForms' => auth()->user()->hasRole('karu', 'produksi'),
        'machines' => $machines,
        'minDate' => $range->min ?? null,
        'maxDate' => $range->max ?? null,
    ]))" x-init="init()" class="space-y-4">

    @include('dashboard._filters')

    {{-- ===================== KPI ===================== --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <template x-for="k in kpis()" :key="k.key">
            <div class="card card-pad !py-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[12.5px] font-medium" style="color:var(--ink-2)" x-text="k.label"></span>
                    <span class="text-[11px]" style="color:var(--muted)" x-text="k.hint"></span>
                </div>
                <div class="kpi-value mt-1.5" x-text="d ? k.value : '—'"></div>
                <div class="mt-1 text-[12px] flex items-center gap-1" x-show="d && k.delta !== null">
                    <span :class="k.good ? 'delta-up' : 'delta-down'" x-text="k.deltaText"></span>
                    <span style="color:var(--muted)">vs periode lalu</span>
                </div>
                <div class="mt-1 text-[12px]" style="color:var(--muted)" x-show="d && k.sub" x-text="k.sub"></div>
            </div>
        </template>
    </div>

    {{-- ===================== HASIL & YIELD ===================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card card-pad xl:col-span-2">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="card-title">Hasil produksi</div>
                    <div class="card-sub">Baik, NC, dan Reject (pcs) per <span x-text="periodLabel()"></span></div>
                </div>
                <div class="seg">
                    <button :class="prodMode==='stack' && 'on'" @click="prodMode='stack'; renderProduction()">Jumlah</button>
                    <button :class="prodMode==='pct' && 'on'" @click="prodMode='pct'; renderProduction()">Persen</button>
                </div>
            </div>
            <div id="ch-production" class="mt-2 min-h-[300px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Yield & cutting time</div>
            <div class="card-sub">Yield = (Baik + NC) × panjang hasil ÷ bahan baku · Cutting time = (jam kerja − downtime) ÷ jam kerja</div>
            <div id="ch-yield" class="mt-2 min-h-[300px]"></div>
        </div>
    </div>

    {{-- ===================== DOWNTIME ===================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card card-pad xl:col-span-2">
            <div class="card-title">Tren downtime per kategori</div>
            <div class="card-sub">Jam downtime per <span x-text="periodLabel()"></span></div>
            <div id="ch-downtime-trend" class="mt-2 min-h-[300px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Komposisi downtime</div>
            <div class="card-sub">Total jam per kategori</div>
            <div id="ch-downtime-cat" class="mt-2 min-h-[300px]"></div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
        <div class="card card-pad">
            <div class="card-title">Pareto downtime · 10 alasan teratas</div>
            <div class="card-sub">Jam downtime; arahkan kursor untuk melihat % kumulatif</div>
            <div id="ch-downtime-pareto" class="mt-2 min-h-[340px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Pareto reject · 10 jenis teratas</div>
            <div class="card-sub">Jumlah pcs reject per jenis</div>
            <div id="ch-defect-pareto" class="mt-2 min-h-[340px]"></div>
        </div>
    </div>

    {{-- ===================== NC / SHIFT / AREA ===================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div class="card card-pad">
            <div class="card-title">Penyebab NC</div>
            <div class="card-sub">NC = barang tidak sesuai (umumnya kondisi bahan)</div>
            <div id="ch-nc" class="mt-2 min-h-[300px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Perbandingan shift</div>
            <div class="card-sub">Hasil per shift</div>
            <div id="ch-shift" class="mt-1 min-h-[200px]"></div>
            <div class="overflow-x-auto -mx-1">
            <table class="tbl tbl-compact mt-1">
                <thead><tr><th>Shift</th><th class="num" title="Cutting time">Cutting</th><th class="num">NC</th><th class="num">Reject</th><th class="num" title="Downtime">DT</th></tr></thead>
                <tbody>
                <template x-for="s in (d?.shifts || [])" :key="s.shift">
                    <tr><td x-text="'Shift ' + s.shift"></td><td class="num" x-text="pct(s.cutting_pct)"></td><td class="num" x-text="pct(s.nc_pct)"></td>
                        <td class="num" x-text="pct(s.reject_pct)"></td><td class="num" x-text="num(s.downtime_hours) + ' j'"></td></tr>
                </template>
                </tbody>
            </table>
            </div>
        </div>
        <div class="card card-pad md:col-span-2 xl:col-span-1">
            <div class="card-title">Perbandingan area</div>
            <div class="card-sub">Hasil baik per area & gedung</div>
            <div id="ch-area" class="mt-2 min-h-[300px]"></div>
        </div>
    </div>

    {{-- ===================== HEATMAP ===================== --}}
    <div class="card card-pad">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <div class="card-title">Cutting time % per mesin</div>
                <div class="card-sub">Semakin gelap = mesin semakin lama benar-benar memotong (downtime sedikit). Abu-abu = tidak ada data.</div>
            </div>
            <div class="flex items-center gap-1.5 text-[11.5px]" style="color:var(--muted)">
                <span>&lt;60%</span>
                <template x-for="c in heatColors()"><span class="w-5 h-3 rounded-[3px]" :style="'background:' + c"></span></template>
                <span>&gt;92%</span>
            </div>
        </div>
        <div id="ch-heatmap" class="mt-2"></div>
    </div>

    {{-- ===================== SAP vs LAPANGAN ===================== --}}
    <div class="card">
        <div class="card-pad !pb-2 flex flex-wrap items-start justify-between gap-2">
            <div>
                <div class="card-title">Yield SAP vs data lapangan (per order)</div>
                <div class="card-sub">Order yang selesai dalam periode ini. Ditandai jika selisih &gt; <span x-text="d?.sap.threshold"></span> poin atau GR SAP &gt; 100%.</div>
            </div>
            <div class="flex gap-2 flex-wrap" x-show="d">
                <span class="chip chip-muted" x-text="(d?.sap.compared || 0) + ' order dibandingkan'"></span>
                <span class="chip" :class="d?.sap.flagged_count ? 'chip-warn' : 'chip-ok'" x-text="(d?.sap.flagged_count || 0) + ' order beda'"></span>
            </div>
        </div>
        <div class="overflow-x-auto px-2 pb-2">
            <table class="tbl">
                <thead><tr><th>Order</th><th>Customer</th><th>Material</th><th class="num">GI SAP (kg)</th><th class="num">GR SAP</th>
                    <th class="num">Yield lapangan</th><th class="num">Selisih</th></tr></thead>
                <tbody>
                <template x-for="r in (d?.sap.rows || [])" :key="r.order">
                    <tr>
                        <td><a class="underline underline-offset-2" :href="'{{ url('orders') }}/' + r.order" x-text="r.order"></a></td>
                        <td x-text="r.customer || '-'"></td>
                        <td class="font-mono text-[12px]" x-text="r.material || '-'"></td>
                        <td class="num" x-text="num(r.gi_kg, 1)"></td>
                        <td class="num" x-text="pct(r.sap)"></td>
                        <td class="num" x-text="pct(r.field_nc)"></td>
                        <td class="num"><span class="chip" :class="Math.abs(r.diff) > 5 || r.sap > 100 ? 'chip-crit' : 'chip-warn'" x-text="(r.diff > 0 ? '+' : '') + r.diff + ' poin'"></span></td>
                    </tr>
                </template>
                <tr x-show="d && !d.sap.rows.length"><td colspan="7" class="text-center !py-8" style="color:var(--muted)">Tidak ada selisih yang perlu dicek (atau data SAP belum diimport untuk periode ini).</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ===================== PERFORMA MESIN + CUSTOMER ===================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card xl:col-span-2 flex flex-col">
            <div class="card-pad !pb-2">
                <div class="card-title">Performa per mesin</div>
                <div class="card-sub">Klik judul kolom untuk mengurutkan</div>
            </div>
            <div class="overflow-auto max-h-[460px] px-2 pb-2">
                <table class="tbl">
                    <thead><tr>
                        <template x-for="c in machineCols" :key="c.key">
                            <th class="th-sort" :class="c.num && 'num'" @click="sortBy(c.key)">
                                <span x-text="c.label"></span><span x-show="sort.key===c.key" x-text="sort.dir>0 ? ' ↑' : ' ↓'"></span>
                            </th>
                        </template>
                    </tr></thead>
                    <tbody>
                    <template x-for="m in sortedMachines()" :key="m.id">
                        <tr class="cursor-pointer" @click="f.machine_id = String(m.id); load()">
                            <td class="font-medium" x-text="m.code"></td>
                            <td x-text="m.area || '-'"></td>
                            <td class="num" x-text="num(m.ok)"></td>
                            <td class="num" x-text="pct(m.nc_pct)"></td>
                            <td class="num" x-text="pct(m.reject_pct)"></td>
                            <td class="num" x-text="num(m.downtime_hours)"></td>
                            <td class="num"><span class="chip" :class="badge(m.cutting_pct, 85, 75)" x-text="pct(m.cutting_pct)"></span></td>
                            <td class="num" x-text="pct(m.achievement)"></td>
                            <td class="num" x-text="pct(m.yield_sap)"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Customer teratas</div>
            <div class="card-sub">Hasil baik (pcs)</div>
            <div id="ch-customers" class="mt-2 min-h-[380px]"></div>
        </div>
    </div>

</div>
</x-layouts.app>
