<x-layouts.app title="Dashboard">
<div x-data="dashboard(@js([
        'filters' => $filters,
        'endpoint' => route('dashboard.data'),
        'detailEndpoint' => route('dashboard.downtime-detail'),
        'trendEndpoint' => route('dashboard.trend'),
        'areas' => $areas,
        'canOpenForms' => auth()->user()->hasRole('karu', 'produksi'),
        'customerUrl' => auth()->user()->hasRole('produksi') ? route('customers.show', '__ID__') : null,
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
                    <span class="flex items-center gap-0.5 min-w-0">
                        <span class="text-[12.5px] font-medium" style="color:var(--ink-2)" x-text="k.label"></span>
                        <x-info-tip :title="'Rumus'"><div x-html="k.info"></div><div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih. ▲▼ = dibanding periode sebelumnya dengan panjang yang sama.</div></x-info-tip>
                    </span>
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

    {{-- ===================== CUTTING TIME PER MESIN ===================== --}}
    <div class="card card-pad">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <div class="card-title flex items-center gap-1">Cutting time % per mesin
            <x-info-tip>
                <ul>
                    <li><b>Cutting time %</b> = Cutting time ÷ Jam kerja × 100, per mesin</li>
                    <li>Warna: hijau ≥ 85%, kuning ≥ 75%, merah &lt; 75%</li>
                    <li><b>Garis putus-putus / Rata-rata</b> = Cutting time ÷ Jam kerja semua mesin</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
                <div class="card-sub">Cutting time ÷ jam kerja per mesin · hijau ≥ 85%, kuning ≥ 75%, merah &lt; 75% · garis putus-putus = rata-rata · <b>klik batang</b> untuk lihat downtime mesin itu</div>
            </div>
            <div class="text-right" x-show="d">
                <div class="text-[11.5px]" style="color:var(--muted)">Rata-rata</div>
                <div class="text-[20px] font-semibold leading-tight" x-text="pct(d?.kpi.cutting_pct)"></div>
            </div>
        </div>
        <div id="ch-cutting-machine" class="mt-2 min-h-[340px] cursor-pointer"></div>
    </div>

    @include('dashboard._downtime_detail', ['anchor' => 'machine'])

    {{-- ===================== HASIL & YIELD ===================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
        <div class="card card-pad">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="card-title flex items-center gap-1">Hasil produksi
            <x-info-tip>
                <ul>
                    <li><b>Baik, NC, Reject</b> = jumlah pcs per hari / minggu / bulan (sesuai pilihan "Tampilkan per")</li>
                    <li><b>Persen</b> = pcs ÷ (Baik + NC + Reject) × 100</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih. Area &amp; mesin bisa difilter khusus untuk grafik ini.</div>
            </x-info-tip>
            </div>
                    <div class="card-sub">Baik, NC, dan Reject (pcs) per <span x-text="periodLabel()"></span><span x-show="chartScope('production')" x-text="' · ' + chartScope('production')"></span></div>
                </div>
                <div class="seg shrink-0">
                    <button :class="prodMode==='stack' && 'on'" @click="prodMode='stack'; renderProduction()">Jumlah</button>
                    <button :class="prodMode==='pct' && 'on'" @click="prodMode='pct'; renderProduction()">Persen</button>
                </div>
            </div>
            @include('dashboard._chart_filter', ['chart' => 'production'])
            <div id="ch-production" class="mt-2 min-h-[300px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Yield & cutting time
            <x-info-tip>
                <ul>
                    <li><b>Yield %</b> = Σ (panjang hasil × (Baik + NC)) ÷ Σ (panjang bahan × qty bahan) × 100</li>
                    <li><b>Cutting time %</b> = Cutting time ÷ Jam kerja × 100</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih. Area &amp; mesin bisa difilter khusus untuk grafik ini.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Yield = (Baik + NC) × panjang hasil ÷ bahan baku · Cutting time = (jam kerja − downtime) ÷ jam kerja<span x-show="chartScope('yield')" x-text="' · ' + chartScope('yield')"></span></div>
            @include('dashboard._chart_filter', ['chart' => 'yield'])
            <div id="ch-yield" class="mt-2 min-h-[300px]"></div>
        </div>
    </div>

    {{-- ===================== DOWNTIME ===================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card card-pad xl:col-span-2">
            <div class="card-title flex items-center gap-1">Tren Set Up &amp; Downtime
            <x-info-tip>
                <ul>
                    <li><b>Batang</b> = jam Set Up &amp; Downtime per periode, dikelompokkan sesuai kolom DT di sheet</li>
                    <li><b>Garis</b> = jam cutting time per periode (sumbu kanan)</li>
                    <li>Jam = menit ÷ 60</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Jam Set Up &amp; Downtime (batang, sumbu kiri) dan jam cutting time (garis, sumbu kanan) per <span x-text="periodLabel()"></span> · <b>klik batang</b> untuk lihat penyebabnya</div>
            <div id="ch-downtime-trend" class="mt-2 min-h-[300px] cursor-pointer"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Komposisi Set Up &amp; Downtime
            <x-info-tip>
                <ul>
                    <li><b>Jam kerja</b> = Set Up + Downtime + Cutting time</li>
                    <li>Tiap potongan = total jam kelompok itu dalam periode terpilih</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Jam kerja = Set Up + Downtime (kolom sheet DT) + Cutting time · <b>klik</b> Set Up / Downtime untuk lihat penyebabnya</div>
            <div id="ch-downtime-cat" class="mt-2 min-h-[300px] cursor-pointer"></div>
        </div>
    </div>

    @include('dashboard._downtime_detail', ['anchor' => 'trend'])

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Pareto downtime · 10 kolom DT teratas
            <x-info-tip>
                <ul>
                    <li><b>Batang</b> = total jam per kolom DT, 10 terbesar</li>
                    <li><b>% share</b> = jam kolom itu ÷ total jam downtime × 100</li>
                    <li><b>% kumulatif</b> = jumlah share dari kolom terbesar sampai kolom itu</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Jam downtime · <b>klik batang</b> untuk lihat mesinnya</div>
            <div id="ch-downtime-pareto" class="mt-2 min-h-[340px] cursor-pointer"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Pareto reject · 10 jenis teratas
            <x-info-tip>
                <ul>
                    <li><b>Batang</b> = total pcs reject per jenis, 10 terbesar</li>
                    <li><b>% share</b> = pcs jenis itu ÷ total pcs reject × 100</li>
                    <li>Kode mesin di ujung batang = mesin penyumbang reject terbanyak</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Jumlah pcs reject per jenis · nama mesin penyumbang di ujung batang</div>
            <div id="ch-defect-pareto" class="mt-2 min-h-[340px]"></div>
        </div>
    </div>

    @include('dashboard._downtime_detail', ['anchor' => 'pareto'])

    {{-- ===================== NC / SHIFT / AREA ===================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Penyebab NC
            <x-info-tip>
                <ul>
                    <li><b>Potongan</b> = total pcs NC per keterangan penyebab NC, 5 terbesar; sisanya digabung jadi "Lainnya"</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih — kolom NC &amp; penyebab NC.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">NC = barang tidak sesuai (umumnya kondisi bahan)</div>
            <div id="ch-nc" class="mt-2 min-h-[300px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Perbandingan shift
            <x-info-tip>
                <ul>
                    <li><b>Grafik</b> = pcs Baik, NC, Reject per shift</li>
                    <li><b>Cutting</b> = Cutting time ÷ Jam kerja × 100</li>
                    <li><b>NC / Reject</b> = pcs ÷ total pcs × 100</li>
                    <li><b>DT</b> = jam downtime</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
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
            <div class="card-title flex items-center gap-1">Perbandingan area
            <x-info-tip>
                <ul>
                    <li><b>Grafik</b> = pcs Baik, NC, Reject per area</li>
                    <li>Daftar mesin = mesin yang berproduksi di area itu, dengan total pcs baik</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Hasil per area · mesin yang berproduksi di tiap area</div>
            <div id="ch-area" class="mt-2 min-h-[260px]"></div>
            <div class="space-y-2 mt-1">
                <template x-for="a in (d?.areas || [])" :key="a.area">
                    <div class="text-[12.5px]">
                        <div class="flex justify-between gap-2">
                            <b x-text="a.area"></b>
                            <span style="color:var(--muted)" x-text="a.machines.length + ' mesin · ' + short(a.ok) + ' pcs baik'"></span>
                        </div>
                        <div class="mt-0.5 leading-relaxed" style="color:var(--ink-2)" x-text="a.machines.map(m => m.code).join(', ')"></div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Yield SAP vs data lapangan: disembunyikan sementara (data tetap dihitung di DashboardService::sapCompare). --}}

    {{-- ===================== PERFORMA MESIN + CUSTOMER ===================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card xl:col-span-2 flex flex-col">
            <div class="card-pad !pb-2">
                <div class="card-title flex items-center gap-1">Performa per mesin
            <x-info-tip>
                <ul>
                    <li><b>Baik</b> = total pcs baik</li>
                    <li><b>NC / Reject</b> = pcs ÷ total pcs × 100</li>
                    <li><b>Downtime</b> = jam downtime (menit ÷ 60)</li>
                    <li><b>Cutting time</b> = Cutting time ÷ Jam kerja × 100 (hijau ≥ 85%, kuning ≥ 75%)</li>
                    <li><b>Yield</b> = Σ (panjang hasil × (Baik + NC)) ÷ Σ (panjang bahan × qty bahan) × 100</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
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
                        <tr class="cursor-pointer" @click="f.machine_ids = [String(m.id)]; load()" title="Klik untuk tampilkan mesin ini saja">
                            <td class="font-medium" x-text="m.code"></td>
                            <td x-text="m.area || '-'"></td>
                            <td class="num" x-text="num(m.ok)"></td>
                            <td class="num" x-text="pct(m.nc_pct)"></td>
                            <td class="num" x-text="pct(m.reject_pct)"></td>
                            <td class="num" x-text="num(m.downtime_hours)"></td>
                            <td class="num"><span class="chip" :class="badge(m.cutting_pct, 85, 75)" x-text="pct(m.cutting_pct)"></span></td>
                            <td class="num" x-text="pct(m.yield_sap)"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card card-pad">
            <div class="card-title flex items-center gap-1">Customer teratas
            <x-info-tip>
                <ul>
                    <li><b>Batang</b> = total pcs baik per customer, 8 terbesar</li>
                </ul>
                <div class="info-src">Sumber: data produksi hasil import Excel, sesuai filter periode, shift, gudang, area &amp; mesin yang sedang dipilih.</div>
            </x-info-tip>
            </div>
            <div class="card-sub">Hasil baik (pcs)<span x-show="cfg.customerUrl"> · <b>klik</b> untuk detail customer</span></div>
            <div id="ch-customers" class="mt-2 min-h-[380px]" :class="cfg.customerUrl && 'cursor-pointer'"></div>
        </div>
    </div>

</div>
</x-layouts.app>
