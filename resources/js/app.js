import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';

window.Alpine = Alpine;
window.ApexCharts = ApexCharts;

/* ------------------------------------------------------------------ tema */
window.toggleTheme = () => {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
    window.dispatchEvent(new CustomEvent('theme-changed'));
};

/* Sidebar ringkas / lebar (disimpan per browser). Grafik digambar ulang setelah lebar berubah. */
window.toggleSidebar = () => {
    if (window.matchMedia('(max-width: 1023px)').matches) return;
    const root = document.documentElement;
    const collapsed = root.getAttribute('data-sidebar') === 'collapsed';
    if (collapsed) root.removeAttribute('data-sidebar'); else root.setAttribute('data-sidebar', 'collapsed');
    try { localStorage.setItem('sidebar', collapsed ? 'expanded' : 'collapsed'); } catch (e) {}
    setTimeout(() => window.dispatchEvent(new Event('resize')), 230);
};

const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';
/* Layar HP: label grafik dipersingkat supaya tidak terpotong / keluar bingkai */
const isMobile = () => window.matchMedia('(max-width: 639px)').matches;

/* Palet kategorikal tervalidasi (urutan tetap, tidak pernah diputar) */
const SERIES = {
    light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
    dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#0ca30c', '#9085e9', '#e66767'],
};
const CRITICAL = '#d03b3b';
const OTHER_GRAY = { light: '#b5b3ad', dark: '#5e5d59' };

function theme() {
    const d = isDark();
    return {
        mode: d ? 'dark' : 'light',
        series: d ? SERIES.dark : SERIES.light,
        ink: d ? '#f0efec' : '#0b0b0b',
        ink2: d ? '#c3c2b7' : '#52514e',
        muted: '#898781',
        grid: d ? '#2c2c2a' : '#e1e0d9',
        axis: d ? '#383835' : '#c3c2b7',
        surface: d ? '#1a1a19' : '#ffffff',
        other: d ? OTHER_GRAY.dark : OTHER_GRAY.light,
        empty: d ? '#232322' : '#f0efec',
    };
}

const nf = new Intl.NumberFormat('id-ID');
const fmt = (v, d = 0) => (v === null || v === undefined || isNaN(v)) ? '-' : new Intl.NumberFormat('id-ID', { maximumFractionDigits: d, minimumFractionDigits: d }).format(v);
const short = (v) => {
    if (v === null || v === undefined) return '-';
    const a = Math.abs(v);
    if (a >= 1e6) return fmt(v / 1e6, 1) + ' jt';
    if (a >= 1e3) return fmt(v / 1e3, a >= 1e5 ? 0 : 1) + ' rb';
    return fmt(v, 0);
};
/* Batas sumbu yang "bulat" (1 / 2 / 2,5 / 5 × 10^n) supaya label sumbu enak dibaca */
const niceCeil = (v) => {
    if (!(v > 0)) return 1;
    const step = Math.pow(10, Math.floor(Math.log10(v)));
    return [1, 2, 2.5, 5, 10].map(m => m * step).find(x => x >= v);
};
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const fmtDate = (s) => { if (!s) return '-'; const [y, m, d] = s.split('-'); return `${+d} ${MONTHS[+m - 1]}`; };
const periodLabel = (s, period) => {
    if (!s) return '';
    const [y, m, d] = s.split('-');
    if (period === 'month') return `${MONTHS[+m - 1]} ${y.slice(2)}`;
    if (period === 'week') return `Mg ${+d} ${MONTHS[+m - 1]}`;
    return `${+d} ${MONTHS[+m - 1]}`;
};

/* Opsi dasar semua grafik: garis tipis, grid samar, tooltip, tanpa sumbu ganda */
function base(t, extra = {}) {
    return {
        chart: {
            fontFamily: "'Geist Variable', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
            foreColor: t.muted,
            background: 'transparent',
            toolbar: { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } },
            animations: { enabled: false },
            parentHeightOffset: 0,
            ...(extra.chart || {}),
        },
        theme: { mode: t.mode },
        grid: { borderColor: t.grid, strokeDashArray: 0, padding: { left: 6, right: 8 }, xaxis: { lines: { show: false } } },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: t.ink2 }, markers: { size: 5, shape: 'circle' }, itemMargin: { horizontal: 8 } },
        tooltip: { theme: t.mode, style: { fontSize: '12px' } },
        states: { hover: { filter: { type: 'darken', value: 0.9 } } },
        ...Object.fromEntries(Object.entries(extra).filter(([k]) => k !== 'chart')),
    };
}

/* ------------------------------------------------------------------ komponen dashboard */
Alpine.data('dashboard', (cfg) => ({
    cfg,
    f: { from: cfg.filters.from, to: cfg.filters.to, period: cfg.filters.period, shift: '', area_id: '', building: '', machine_ids: [] },
    initial: null,
    d: null,
    loading: false,
    preset: '30d',
    prodMode: 'stack',
    charts: {},
    sort: { key: 'ok', dir: -1 },
    reqId: 0,
    dt: { open: false, loading: false, key: '', name: '', color: '', bucket: null, reason: null, machine: null, anchor: 'trend', data: null, reqId: 0 },
    // filter area & mesin milik grafik Hasil produksi / Yield (di luar filter utama)
    cf: { production: { area_id: '', machine_id: '' }, yield: { area_id: '', machine_id: '' } },
    cdata: { production: null, yield: null },
    creq: { production: 0, yield: 0 },
    presets: [
        { key: '1d', label: 'Hari terakhir' },
        { key: '7d', label: '7 hari' },
        { key: '30d', label: '30 hari' },
        { key: 'mtd', label: 'Bulan ini' },
        { key: '90d', label: '3 bulan' },
        { key: 'all', label: 'Semua' },
    ],
    machineCols: [
        { key: 'code', label: 'Mesin' }, { key: 'area', label: 'Area' }, { key: 'ok', label: 'Baik (pcs)', num: true },
        { key: 'nc_pct', label: 'NC', num: true }, { key: 'reject_pct', label: 'Reject', num: true },
        { key: 'downtime_hours', label: 'Downtime (j)', num: true }, { key: 'cutting_pct', label: 'Cutting time', num: true },
        { key: 'yield_sap', label: 'Yield', num: true },
    ],

    init() {
        this.initial = JSON.parse(JSON.stringify(this.f));
        window.addEventListener('theme-changed', () => this.renderAll());
        window.matchMedia('(max-width: 639px)').addEventListener('change', () => this.renderAll());
        // Filter terakhir dipakai lagi saat kembali ke halaman ini (sampai user menekan Reset).
        const saved = this.loadSaved();
        if (saved) {
            const { machine_id: oldMachine, ...rest } = saved.f; // format lama: satu mesin
            Object.assign(this.f, rest);
            if (!Array.isArray(this.f.machine_ids)) this.f.machine_ids = oldMachine ? [String(oldMachine)] : [];
            // area / mesin yang sudah tidak ada (mis. Area 04 digabung ke Area 03) kembali ke "Semua"
            if (this.f.area_id && !cfg.machines.some(m => String(m.area_id) === String(this.f.area_id))) this.f.area_id = '';
            this.f.machine_ids = this.f.machine_ids.map(String).filter(id => cfg.machines.some(m => String(m.id) === id));
            this.preset = saved.preset;
            // Periode cepat (mis. "30 hari") dihitung ulang dari data terbaru; tanggal manual dipakai apa adanya.
            if (saved.preset && saved.preset !== 'custom') return this.applyPreset(saved.preset);
        }
        this.load();
    },
    storeKey() { return 'filters:' + (cfg.mode || 'dashboard'); },
    loadSaved() {
        try {
            const s = JSON.parse(localStorage.getItem(this.storeKey()) || 'null');
            return s && s.f ? s : null;
        } catch (e) { return null; }
    },
    saveFilters() {
        try {
            if (this.isFiltered() || this.preset !== '30d') localStorage.setItem(this.storeKey(), JSON.stringify({ f: this.f, preset: this.preset }));
            else localStorage.removeItem(this.storeKey());
        } catch (e) {}
    },

    // ---------- util tampilan
    num: (v, d = 0) => fmt(v, d),
    short: (v) => short(v),
    pct: (v) => (v === null || v === undefined) ? '-' : fmt(v, 1) + '%',
    fmtDate,
    fmtRange(r) { return r ? `${fmtDate(r[0])} – ${fmtDate(r[1])}` : ''; },
    periodLabel() { return { day: 'hari', week: 'minggu', month: 'bulan' }[this.f.period]; },
    badge(v, good, warn) { if (v === null) return 'chip-muted'; return v >= good ? 'chip-ok' : (v >= warn ? 'chip-warn' : 'chip-crit'); },
    isFiltered() { return JSON.stringify(this.f) !== JSON.stringify(this.initial); },
    machineOptions() {
        return cfg.machines.filter(m => (!this.f.area_id || String(m.area_id) === String(this.f.area_id)) && (!this.f.building || m.building === this.f.building));
    },
    toggleMachine(id) {
        id = String(id);
        this.f.machine_ids = this.f.machine_ids.includes(id) ? this.f.machine_ids.filter(x => x !== id) : [...this.f.machine_ids, id];
    },
    machineLabel() {
        const codes = this.f.machine_ids.map(id => cfg.machines.find(m => String(m.id) === id)?.code).filter(Boolean);
        if (!codes.length) return 'Semua';
        return codes.length <= 2 ? codes.join(', ') : `${codes.slice(0, 2).join(', ')} +${codes.length - 2}`;
    },
    areaName(id) { return cfg.areas.find(a => String(a.id) === String(id))?.name || ''; },
    /* Parameter URL: array mesin dikirim sebagai "1,2,3" */
    query(extra = {}) {
        const params = { ...this.f, machine_ids: this.f.machine_ids.join(','), ...extra };
        return new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined));
    },
    reset() { this.f = JSON.parse(JSON.stringify(this.initial)); this.preset = '30d'; this.load(); },

    // ---------- filter area & mesin per grafik
    hasChartFilter(key) { return !!(this.cf[key].area_id || this.cf[key].machine_id); },
    chartMachineOptions(key) {
        const area = this.cf[key].area_id;
        return cfg.machines.filter(m => (!area || String(m.area_id) === String(area)) && (!this.f.building || m.building === this.f.building));
    },
    chartScope(key) {
        const c = this.cf[key];
        if (c.machine_id) return 'Mesin ' + (cfg.machines.find(m => String(m.id) === String(c.machine_id))?.code || '');
        if (c.area_id) return cfg.areas.find(a => String(a.id) === String(c.area_id))?.name || '';
        return '';
    },
    chartTrend(key) { return this.hasChartFilter(key) ? (this.cdata[key] || []) : (this.d?.trend || []); },
    renderChart(key) { return key === 'production' ? this.renderProduction() : this.renderYield(); },
    async loadChart(key) {
        if (!this.d) return;
        if (!this.hasChartFilter(key)) { this.cdata[key] = null; return this.renderChart(key); }
        const id = ++this.creq[key];
        const c = this.cf[key];
        const qs = this.query({ area_id: c.area_id, machine_id: c.machine_id });
        const res = await fetch(cfg.trendEndpoint + '?' + qs, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (id !== this.creq[key]) return;
        this.cdata[key] = data.trend;
        this.$nextTick(() => this.renderChart(key));
    },

    applyPreset(key) {
        this.preset = key;
        const max = cfg.maxDate ? new Date(cfg.maxDate + 'T00:00:00') : new Date();
        const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const sub = (n) => { const d = new Date(max); d.setDate(d.getDate() - n); return d; };
        let from = max;
        if (key === '7d') from = sub(6);
        if (key === '30d') from = sub(29);
        if (key === '90d') from = sub(89);
        if (key === 'mtd') from = new Date(max.getFullYear(), max.getMonth(), 1);
        if (key === 'all') from = cfg.minDate ? new Date(cfg.minDate + 'T00:00:00') : sub(365);
        this.f.from = iso(from);
        this.f.to = iso(max);
        const days = (max - from) / 864e5 + 1;
        this.f.period = days <= 45 ? 'day' : (days <= 200 ? 'week' : 'month');
        this.load();
    },

    async load() {
        this.saveFilters();
        const id = ++this.reqId;
        this.loading = true;
        const qs = this.query();
        try {
            const res = await fetch(cfg.endpoint + '?' + qs, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (id !== this.reqId) return; // abaikan respons lama
            this.d = data;
            this.f.period = data.filters.period;
            this.$nextTick(() => this.renderAll());
            ['production', 'yield'].forEach(k => this.hasChartFilter(k) && this.loadChart(k));
            // filter berubah: rincian ikut periode baru
            if (this.dt.open) this.openDowntime(this.dt.key, { machine: this.dt.machine, reason: this.dt.reason, anchor: this.dt.anchor, scroll: false });
        } finally {
            if (id === this.reqId) this.loading = false;
        }
    },

    kpis() {
        const k = this.d?.kpi || {}, p = this.d?.kpi_prev || {};
        const delta = (cur, prev, higherIsGood = true, isPct = false) => {
            if (cur === null || cur === undefined || prev === null || prev === undefined || (!isPct && !prev)) return { delta: null };
            const diff = isPct ? cur - prev : (cur - prev) / prev * 100;
            const good = higherIsGood ? diff >= 0 : diff <= 0;
            const arrow = diff >= 0 ? '▲' : '▼';
            return { delta: diff, good, deltaText: `${arrow} ${fmt(Math.abs(diff), 1)}${isPct ? ' poin' : '%'}` };
        };
        return [
            { key: 'ok', label: 'Hasil baik', hint: 'pcs', value: short(k.ok), sub: `${fmt(k.ok_kg / 1000, 1)} ton · ${fmt(k.reports)} laporan`, ...delta(k.ok, p.ok),
                info: '<b>Hasil baik</b> = total pcs OK.<br><b>Ton</b> = total berat OK ÷ 1.000. <b>Laporan</b> = jumlah baris laporan produksi.' },
            { key: 'yield', label: 'Yield', hint: '(Baik+NC) ÷ bahan', value: this.pct(k.yield_sap), sub: 'Sesuai kolom Yield di Excel', ...delta(k.yield_sap, p.yield_sap, true, true),
                info: '<b>Yield</b> = Σ (panjang hasil × (Baik + NC)) ÷ Σ (panjang bahan × qty bahan) × 100.' },
            { key: 'nc', label: 'NC', hint: '% total', value: this.pct(k.nc_pct), sub: `${short(k.nc)} pcs`, ...delta(k.nc_pct, p.nc_pct, false, true),
                info: '<b>NC %</b> = pcs NC ÷ total pcs cutting × 100. Makin kecil makin bagus.' },
            { key: 'reject', label: 'Reject', hint: '% total', value: this.pct(k.reject_pct), sub: `${short(k.reject)} pcs`, ...delta(k.reject_pct, p.reject_pct, false, true),
                info: '<b>Reject %</b> = pcs reject ÷ total pcs cutting × 100. Makin kecil makin bagus.' },
            { key: 'cut', label: 'Cutting time', hint: '% jam kerja', value: this.pct(k.cutting_pct), sub: `Capaian ${this.pct(k.achievement)}`, ...delta(k.cutting_pct, p.cutting_pct, true, true),
                info: '<b>Cutting time %</b> = Cutting time ÷ Jam kerja × 100.<br><b>Capaian</b> = kapasitas cutting ÷ kapasitas × 100.' },
            { key: 'dt', label: 'Downtime', hint: 'jam', value: fmt(k.downtime_hours, 0) + ' j', sub: `${k.machines || 0} mesin · ${k.days || 0} hari`, ...delta(k.downtime_hours, p.downtime_hours, false),
                info: '<b>Downtime</b> = total menit downtime ÷ 60 (jam).<br><b>Mesin / hari</b> = jumlah mesin &amp; tanggal yang punya data.' },
        ];
    },

    sortBy(key) {
        this.sort = this.sort.key === key ? { key, dir: -this.sort.dir } : { key, dir: key === 'code' || key === 'area' ? 1 : -1 };
    },
    sortedMachines() {
        const rows = [...(this.d?.machines || [])];
        const { key, dir } = this.sort;
        return rows.sort((a, b) => {
            const x = a[key], y = b[key];
            if (x === null || x === undefined) return 1;
            if (y === null || y === undefined) return -1;
            return (typeof x === 'string' ? x.localeCompare(y) : x - y) * dir;
        });
    },

    // ---------- grafik
    draw(id, options) {
        const el = document.getElementById(id);
        if (!el) return;
        if (this.charts[id]) { this.charts[id].destroy(); }
        el.innerHTML = '';
        this.charts[id] = new ApexCharts(el, options);
        this.charts[id].render();
    },
    empty(id, text = 'Belum ada data untuk filter ini') {
        if (this.charts[id]) { this.charts[id].destroy(); delete this.charts[id]; }
        const el = document.getElementById(id);
        if (el) el.innerHTML = `<div class="grid place-items-center h-[260px] text-[13px]" style="color:var(--muted)">${text}</div>`;
    },

    renderAll() {
        if (!this.d) return;
        this.renderProduction();
        this.renderYield();
        this.renderDowntimeTrend();
        this.renderDowntimeCat();
        this.renderPareto('ch-downtime-pareto', this.d.downtime_pareto, 'hours', 'jam', 0,
            (r) => this.openDowntime(r.category_key, { reason: r.name, anchor: 'pareto' }));
        this.renderPareto('ch-defect-pareto', this.d.defect_pareto, 'qty', 'pcs', 0);
        this.renderNc();
        this.renderShift();
        this.renderArea();
        this.renderCuttingMachines();
        this.renderCustomers();
    },

    renderProduction() {
        const t = theme(), tr = this.chartTrend('production');
        if (!tr.length) return this.empty('ch-production');
        const pctMode = this.prodMode === 'pct';
        const keys = ['ok', 'nc', 'reject'];
        const tot = (r) => r.ok + r.nc + r.reject;
        const step = Math.ceil(tr.length / 16); // label total dijarangkan bila batang sangat rapat
        this.draw('ch-production', base(t, {
            chart: { type: 'bar', height: 300, stacked: true, stackType: pctMode ? '100%' : 'normal' },
            series: [
                { name: 'Baik', data: tr.map(r => r.ok) },
                { name: 'NC', data: tr.map(r => r.nc) },
                { name: 'Reject', data: tr.map(r => r.reject) },
            ],
            colors: [t.series[0], t.series[1], t.series[7]], // Baik biru, NC oranye, Reject merah
            plotOptions: { bar: { columnWidth: tr.length > 20 ? '70%' : '50%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last',
                dataLabels: { total: { enabled: !pctMode, offsetY: -2, style: { fontSize: '10px', fontWeight: 600, color: t.ink2 },
                    formatter: (v, o) => (o?.dataPointIndex ?? 0) % step === 0 ? short(v) : '' } } } },
            // angka di dalam batang hanya bila segmennya cukup besar; NC / Reject yang kecil lihat tooltip
            dataLabels: { enabled: true, style: { fontSize: '9.5px', fontWeight: 500, colors: ['#fff'] }, dropShadow: { enabled: false },
                formatter: (v, o) => {
                    const r = tr[o.dataPointIndex], all = tot(r), share = pctMode ? v : (all ? v / all * 100 : 0);
                    if (tr.length > 31 || share < (tr.length > 14 ? 25 : 12)) return '';
                    return pctMode ? fmt(v, 0) + '%' : short(v);
                } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: tr.map(r => periodLabel(r.period, this.f.period)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } } },
            yaxis: { max: pctMode ? 100 : niceCeil(Math.max(...tr.map(tot)) * 1.1), labels: { formatter: v => pctMode ? fmt(v, 0) + '%' : short(v) } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: (v, o) => {
                const r = tr[o.dataPointIndex], all = tot(r), qty = r[keys[o.seriesIndex]];
                return `${fmt(qty)} pcs (${fmt(all ? qty / all * 100 : 0, 1)}%)`;
            } } },
        }));
    },

    renderYield() {
        const t = theme(), tr = this.chartTrend('yield');
        if (!tr.length) return this.empty('ch-yield');
        const step = Math.ceil(tr.length / 12); // angka dijarangkan bila titik sangat rapat
        const vals = tr.flatMap(r => [r.yield_sap, r.cutting_pct]).filter(v => v !== null);
        if (!vals.length) return this.empty('ch-yield');
        this.draw('ch-yield', base(t, {
            chart: { type: 'line', height: 300 },
            series: [
                { name: 'Yield', data: tr.map(r => r.yield_sap) },
                { name: 'Cutting time', data: tr.map(r => r.cutting_pct) },
            ],
            colors: [t.series[0], t.series[2]],
            stroke: { width: 2, curve: 'smooth' },
            markers: { size: tr.length > 20 ? 2 : 4, strokeWidth: 2, strokeColors: t.surface, hover: { size: 6 } },
            dataLabels: { enabled: true, offsetY: -6, style: { fontSize: '9.5px', fontWeight: 600 },
                background: { enabled: true, borderWidth: 0, borderRadius: 3, padding: 3, opacity: 0.9 },
                formatter: (v, o) => v === null || (o.dataPointIndex % step !== 0 && o.dataPointIndex !== tr.length - 1) ? '' : fmt(v, 1) + '%' },
            xaxis: { categories: tr.map(r => periodLabel(r.period, this.f.period)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } }, tickAmount: Math.min(tr.length, 10), tooltip: { enabled: false } },
            yaxis: { min: Math.max(0, Math.floor(Math.min(...vals) / 5) * 5 - 5), max: Math.min(110, Math.ceil(Math.max(...vals) / 5) * 5 + 5),
                tickAmount: 5, labels: { formatter: v => fmt(v, 0) + '%' } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => v === null ? '-' : fmt(v, 2) + '%' } },
        }));
    },

    renderDowntimeTrend() {
        const t = theme(), dt = this.d.downtime_trend;
        if (!dt.periods.length) return this.empty('ch-downtime-trend');
        const colorOf = this.categoryColors(t);
        const n = dt.periods.length, bars = dt.series.length;
        const step = Math.ceil(n / 16); // angka dijarangkan bila batang sangat rapat
        const barMax = Math.max(...dt.periods.map((p, i) => dt.series.reduce((a, s) => a + (s.data[i] || 0), 0)));
        const open = (si, bucket) => si < bars && this.openDowntime(dt.series[si].key, { bucket, anchor: 'trend' });
        this.draw('ch-downtime-trend', base(t, {
            chart: { type: 'line', height: 320, stacked: true, stackOnlyBar: true, events: {
                dataPointSelection: (e, ctx, o) => open(o.seriesIndex, dt.periods[o.dataPointIndex]),
                legendClick: (ctx, i) => open(i, null),
            } },
            legend: { ...base(t).legend, onItemClick: { toggleDataSeries: false } },
            series: [
                ...dt.series.map(s => ({ name: s.name, type: 'column', data: s.data })),
                { name: 'Cutting time', type: 'line', data: dt.cutting || [] },
            ],
            colors: [...dt.series.map(s => colorOf[s.key]), colorOf.cutting],
            plotOptions: { bar: { columnWidth: n > 20 ? '72%' : '50%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last',
                dataLabels: { total: { enabled: true, offsetY: -2, style: { fontSize: '10px', fontWeight: 600, color: t.ink2 },
                    formatter: (v, o) => (o?.dataPointIndex ?? 0) % step === 0 ? fmt(v, 0) : '' } } } },
            stroke: { width: [...dt.series.map(() => 0), 2.5], curve: 'smooth' },
            markers: { size: [...dt.series.map(() => 0), n > 20 ? 2 : 4], strokeWidth: 2, strokeColors: t.surface, hover: { size: 6 } },
            dataLabels: { enabled: true, enabledOnSeries: [bars], offsetY: -8, style: { fontSize: '9.5px', fontWeight: 600 },
                background: { enabled: true, borderWidth: 0, borderRadius: 3, padding: 3, opacity: 0.9 },
                formatter: (v, o) => !v || (o.dataPointIndex % step !== 0 && o.dataPointIndex !== n - 1) ? '' : fmt(v, 0) },
            xaxis: { categories: dt.periods.map(p => periodLabel(p, this.f.period)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } }, tooltip: { enabled: false } },
            yaxis: [
                ...dt.series.map((s, i) => ({ seriesName: dt.series[0].name, show: i === 0, min: 0, max: niceCeil(barMax * 1.15), tickAmount: 4,
                    title: { text: 'Set Up & Downtime (jam)', style: { fontSize: '11px', fontWeight: 500, color: t.muted } }, labels: { formatter: v => fmt(v, 0) } })),
                { seriesName: 'Cutting time', opposite: true, min: 0, max: niceCeil(Math.max(1, ...(dt.cutting || [0])) * 1.15), tickAmount: 4,
                    title: { text: 'Cutting time (jam)', style: { fontSize: '11px', fontWeight: 500, color: t.muted } }, labels: { formatter: v => fmt(v, 0) } },
            ],
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => v === null || v === undefined ? '-' : fmt(v, 1) + ' jam' } },
        }));
    },

    categoryColors(t) {
        return { setup: t.series[3], downtime: t.series[0], cutting: t.series[2] }; // kelompok sesuai sheet DT Excel + cutting time
    },

    /*
     * Klik grafik Set Up & Downtime: tampilkan penyebab (kolom sheet DT), mesin & catatan keterangan.
     * opts: bucket (batang tren yang diklik), reason (batang pareto = satu kolom DT), machine ({id, code}: batang cutting time per mesin),
     * anchor (panel muncul di dekat grafik yang diklik: trend / pareto / machine).
     */
    async openDowntime(key, { bucket = null, reason = null, machine = null, anchor = 'trend', scroll = true } = {}) {
        if (!['setup', 'downtime'].includes(key)) return; // cutting time tidak punya rincian penyebab
        const id = ++this.dt.reqId;
        Object.assign(this.dt, {
            open: true, loading: true, key, bucket, reason, machine, anchor, data: null,
            name: { setup: 'Set Up', downtime: 'Downtime' }[key] || key, color: this.categoryColors(theme())[key],
        });
        if (scroll) this.$nextTick(() => document.getElementById('dt-detail-' + anchor)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
        const qs = this.query();
        qs.set('category', key);
        if (bucket) qs.set('bucket', bucket);
        if (reason) qs.set('reason', reason);
        if (machine) { qs.set('machine_id', machine.id); qs.delete('machine_ids'); qs.delete('area_id'); }
        try {
            const res = await fetch(cfg.detailEndpoint + '?' + qs, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (id === this.dt.reqId) this.dt.data = data;
        } finally {
            if (id === this.dt.reqId) this.dt.loading = false;
        }
    },
    dtTitle() {
        const what = this.dt.reason || this.dt.data?.category || this.dt.name;
        return 'Rincian ' + what + (this.dt.machine ? ' · mesin ' + this.dt.machine.code : '') + ' · ' + this.dtRange();
    },
    dtSub() {
        const r = this.dt.data;
        if (!r) return '';
        if (this.dt.reason) return `Total ${fmt(r.total_hours, 1)} jam · ${r.machines.length} mesin · kelompok ${r.category}`;
        return `Total ${fmt(r.total_hours, 1)} jam · ${r.reasons.length} penyebab (kolom sheet DT)`;
    },
    dtRange() {
        const r = this.dt.data;
        if (!r) return this.dt.bucket ? periodLabel(this.dt.bucket, this.f.period) : 'seluruh periode filter';
        return r.from === r.to ? fmtDate(r.from) : `${fmtDate(r.from)} – ${fmtDate(r.to)}`;
    },

    renderDowntimeCat() {
        const t = theme(), rows = this.d.downtime_category.filter(r => r.hours > 0);
        if (!rows.length) return this.empty('ch-downtime-cat');
        const colorOf = this.categoryColors(t);
        const total = rows.reduce((a, r) => a + r.hours, 0);
        this.draw('ch-downtime-cat', base(t, {
            chart: { type: 'donut', height: 320, events: {
                dataPointSelection: (e, ctx, o) => this.openDowntime(rows[o.dataPointIndex].key, { anchor: 'trend' }),
                legendClick: (ctx, i) => this.openDowntime(rows[i].key, { anchor: 'trend' }),
            } },
            series: rows.map(r => r.hours),
            labels: rows.map(r => r.name),
            colors: rows.map(r => colorOf[r.key]),
            stroke: { width: 2, colors: [t.surface] },
            dataLabels: { enabled: true, formatter: v => fmt(v, 1) + '%', style: { fontSize: '11px', fontWeight: 600, colors: ['#fff'] }, dropShadow: { enabled: false } },
            legend: { position: 'bottom', fontSize: '12px', labels: { colors: t.ink2 }, markers: { size: 5, shape: 'circle' }, onItemClick: { toggleDataSeries: false },
                formatter: (name, o) => `${name}: ${fmt(rows[o.seriesIndex].hours, 0)} j` },
            plotOptions: { pie: { donut: { size: '62%', labels: { show: true,
                name: { fontSize: '12px', color: t.muted },
                value: { fontSize: '22px', fontWeight: 600, color: t.ink, formatter: v => fmt(+v, 0) + ' j' },
                total: { show: true, label: 'Total jam kerja', color: t.muted, fontSize: '12px', formatter: () => fmt(total, 0) + ' j' } } } } },
            tooltip: { theme: t.mode, y: { formatter: v => `${fmt(v, 1)} jam (${fmt(v / total * 100, 1)}%)` } },
        }));
    },

    renderPareto(id, rows, field, unit, dec, onClick = null) {
        const t = theme(), mobile = isMobile();
        if (!rows.length) return this.empty(id);
        // reject: tampilkan mesin penyumbang (mis. Coaxial Over hanya di mesin swaging)
        const machineText = (r, max) => !r.machines ? '' : r.machines.slice(0, max).map(m => m.code).join(', ') + (r.machines.length > max ? ` +${r.machines.length - max}` : '');
        this.draw(id, base(t, {
            chart: { type: 'bar', height: 340, events: onClick ? { dataPointSelection: (e, ctx, o) => onClick(rows[o.dataPointIndex]) } : {} },
            series: [{ name: { jam: 'Downtime', mnt: 'Menit' }[unit] || 'Reject', data: rows.map(r => r[field]) }],
            colors: [t.series[0]],
            plotOptions: { bar: { horizontal: true, barHeight: '62%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, style: { fontSize: '11px', fontWeight: 500, colors: [t.ink2] },
                formatter: (v, o) => {
                    const r = rows[o.dataPointIndex], m = machineText(r, 2);
                    if (mobile) return `${fmt(v, dec)} ${unit}`;
                    return `${fmt(v, dec)} ${unit} · ${fmt(r.share, 0)}%` + (m ? ` · ${m}` : '');
                } },
            xaxis: { categories: rows.map(r => r.name), labels: { formatter: v => short(v) }, axisBorder: { show: false }, axisTicks: { show: false },
                min: 0, max: niceCeil(Math.max(...rows.map(r => r[field])) * (mobile ? 1.3 : (rows[0].machines ? 1.9 : 1.35))), tickAmount: mobile ? 2 : 4 },
            yaxis: { labels: { maxWidth: mobile ? 110 : 190, style: { fontSize: mobile ? '11px' : '12px', colors: t.ink2 } } },
            grid: { borderColor: t.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            legend: { show: false },
            tooltip: { theme: t.mode, y: { formatter: (v, o) => {
                const r = rows[o.dataPointIndex];
                return `${fmt(v, 1)} ${unit} · ${fmt(r.share, 1)}% · kumulatif ${fmt(r.cumulative, 1)}%` + (r.category ? ` · ${r.category}` : '')
                    + (r.machines ? '<br>Mesin: ' + r.machines.slice(0, 8).map(m => `${m.code} (${fmt(m.qty)})`).join(', ') + (r.machines.length > 8 ? ` +${r.machines.length - 8} lainnya` : '') : '');
            } } },
        }));
    },

    renderNc() {
        const t = theme(), rows = this.d.nc_causes;
        if (!rows.length) return this.empty('ch-nc', 'Tidak ada NC pada periode ini');
        const total = rows.reduce((a, r) => a + r.qty, 0);
        this.draw('ch-nc', base(t, {
            chart: { type: 'pie', height: 320 },
            series: rows.map(r => r.qty),
            labels: rows.map(r => r.name),
            colors: rows.map((r, i) => r.name === 'Lainnya' ? t.other : t.series[i]),
            stroke: { width: 2, colors: [t.surface] },
            dataLabels: { enabled: true, formatter: v => v >= 6 ? fmt(v, 0) + '%' : '', style: { fontSize: '11px', fontWeight: 600, colors: ['#fff'] }, dropShadow: { enabled: false } },
            legend: { position: 'bottom', fontSize: '12px', labels: { colors: t.ink2 }, markers: { size: 5, shape: 'circle' } },
            tooltip: { theme: t.mode, y: { formatter: v => `${fmt(v)} pcs (${fmt(v / total * 100, 1)}%)` } },
        }));
    },

    renderShift() {
        const t = theme(), rows = this.d.shifts;
        if (!rows.length) return this.empty('ch-shift');
        this.draw('ch-shift', base(t, {
            chart: { type: 'bar', height: 200, stacked: true },
            series: [
                { name: 'Baik', data: rows.map(r => r.ok) },
                { name: 'NC', data: rows.map(r => r.nc) },
                { name: 'Reject', data: rows.map(r => r.reject) },
            ],
            colors: [t.series[0], t.series[1], t.series[7]], // Baik biru, NC oranye, Reject merah
            plotOptions: { bar: { horizontal: true, barHeight: '55%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: rows.map(r => 'Shift ' + r.shift), labels: { formatter: v => short(v) }, axisBorder: { show: false }, axisTicks: { show: false }, min: 0, tickAmount: 4 },
            yaxis: { labels: { style: { colors: t.ink2, fontSize: '12px' } } },
            grid: { borderColor: t.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => fmt(v) + ' pcs' } },
        }));
    },

    renderArea() {
        const t = theme(), rows = this.d.areas;
        if (!rows.length) return this.empty('ch-area');
        this.draw('ch-area', base(t, {
            chart: { type: 'bar', height: 260, stacked: true },
            series: [
                { name: 'Baik', data: rows.map(r => r.ok) },
                { name: 'NC', data: rows.map(r => r.nc) },
                { name: 'Reject', data: rows.map(r => r.reject) },
            ],
            colors: [t.series[0], t.series[1], t.series[7]], // Baik biru, NC oranye, Reject merah
            plotOptions: { bar: { columnWidth: '52%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last',
                dataLabels: { total: { enabled: true, offsetY: -2, style: { fontSize: '10.5px', fontWeight: 600, color: t.ink2 }, formatter: v => short(v) } } } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: rows.map(r => r.area), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { style: { fontSize: '11px' } } },
            yaxis: { labels: { formatter: v => short(v) } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: (v, o) => {
                const r = rows[o.dataPointIndex];
                return `${fmt(v)} pcs` + (o.seriesIndex === 0 ? ` · cutting time ${this.pct(r.cutting_pct)} · ${r.machines.length} mesin` : '');
            } } },
        }));
    },

    /* Cutting time % per mesin: batang berwarna status + garis yang menyambung puncak batang + garis rata-rata. */
    renderCuttingMachines() {
        const t = theme();
        const rows = (this.d.machines || []).filter(m => m.cutting_pct !== null);
        if (!rows.length) return this.empty('ch-cutting-machine');
        const avg = this.d.kpi?.cutting_pct;
        const color = (v) => v >= 85 ? t.series[2] : (v >= 75 ? t.series[3] : CRITICAL);
        this.draw('ch-cutting-machine', base(t, {
            chart: { type: 'line', height: 340, events: {
                dataPointSelection: (e, ctx, o) => this.openDowntime('downtime', { machine: { id: rows[o.dataPointIndex].id, code: rows[o.dataPointIndex].code }, anchor: 'machine' }),
            } },
            series: [
                { name: 'Cutting time', type: 'column', data: rows.map(r => ({ x: r.code, y: r.cutting_pct, fillColor: color(r.cutting_pct) })) },
                { name: 'Garis tren', type: 'line', data: rows.map(r => ({ x: r.code, y: r.cutting_pct })) },
            ],
            colors: [t.series[0], t.ink2],
            stroke: { width: [0, 2.5], curve: 'smooth' },
            fill: { opacity: [0.92, 1] },
            markers: { size: [0, 4], strokeWidth: 2, strokeColors: t.surface, colors: [t.ink2], hover: { size: 6 } },
            plotOptions: { bar: { columnWidth: rows.length > 25 ? '72%' : '55%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, enabledOnSeries: [0], offsetY: -18, formatter: v => fmt(v, 0) + '%',
                style: { fontSize: '10.5px', fontWeight: 600, colors: [t.ink2] }, background: { enabled: false } },
            xaxis: { type: 'category', axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, rotateAlways: rows.length > (isMobile() ? 6 : 14), style: { fontSize: isMobile() ? '10px' : '11px' } } },
            yaxis: { min: 0, max: 110, tickAmount: 5, labels: { formatter: v => fmt(v, 0) + '%' } },
            annotations: avg === null || avg === undefined ? {} : { yaxis: [{ y: avg, borderColor: t.series[0], strokeDashArray: 5, borderWidth: 1.5,
                label: { text: 'Rata-rata ' + fmt(avg, 1) + '%', position: 'left', textAnchor: 'start', borderWidth: 0,
                    style: { background: t.series[0], color: '#fff', fontSize: '11px', padding: { left: 6, right: 6, top: 2, bottom: 2 } } } }] },
            legend: { show: false },
            tooltip: { theme: t.mode, shared: true, intersect: false, custom: ({ dataPointIndex: i }) => {
                const r = rows[i];
                return `<div class="px-3 py-2 text-[12px]"><div class="font-semibold mb-0.5">${r.code}</div>`
                    + `Cutting time ${fmt(r.cutting_pct, 1)}%<br>Downtime ${fmt(r.downtime_hours, 1)} jam · ${r.area || '-'}`
                    + `<div class="mt-1" style="opacity:.7">Klik untuk lihat penyebab downtime</div></div>`;
            } },
        }));
    },

    renderCustomers() {
        const t = theme(), rows = this.d.customers;
        if (!rows.length) return this.empty('ch-customers');
        this.draw('ch-customers', base(t, {
            chart: { type: 'bar', height: 380, events: cfg.customerUrl ? {
                dataPointSelection: (e, ctx, o) => { location.href = cfg.customerUrl.replace('__ID__', rows[o.dataPointIndex].id); },
            } : {} },
            series: [{ name: 'Hasil baik', data: rows.map(r => r.qty) }],
            colors: [t.series[0]],
            plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, style: { fontSize: '11px', fontWeight: 500, colors: [t.ink2] }, formatter: v => short(v) },
            xaxis: { categories: rows.map(r => r.name), labels: { formatter: v => short(v) }, axisBorder: { show: false }, axisTicks: { show: false },
                min: 0, max: Math.max(...rows.map(r => r.qty)) * 1.3, tickAmount: 3 },
            yaxis: { labels: { maxWidth: isMobile() ? 100 : 150, style: { fontSize: '11.5px', colors: t.ink2 } } },
            grid: { borderColor: t.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            legend: { show: false },
            tooltip: { theme: t.mode, y: { formatter: (v, o) => `${fmt(v)} pcs · ${fmt(rows[o.dataPointIndex].kg / 1000, 1)} ton` } },
        }));
    },
}));

/* ------------------------------------------------------------------ monitoring karu (Monitoring Daily) */
/* Palet papan Monitoring Daily: selalu gelap seperti layar monitor produksi (gambar referensi). */
function boardTheme() {
    return {
        mode: 'dark', series: SERIES.dark, ink: '#f1f5f9', ink2: '#cbd5e1', muted: '#94a3b8', grid: '#24314f', axis: '#33415f',
        surface: '#121a2e', other: OTHER_GRAY.dark, empty: '#1c2742',
        teal: '#5ee6b4', blue: '#60a5fa', gold: '#f5b301', pink: '#f472b6', good: '#22c55e', bad: '#ef4444',
    };
}

Alpine.data('monitoringDaily', (cfg) => ({
    f: { group: cfg.filters.group || '', date: cfg.filters.date, shift: cfg.filters.shift ? String(cfg.filters.shift) : '' },
    initial: null,
    d: null,
    loading: false,
    charts: {},
    reqId: 0,

    init() {
        this.initial = { group: '', date: cfg.today, shift: '' };
        window.addEventListener('theme-changed', () => this.renderAll());
        // Filter terakhir dipakai lagi saat kembali ke halaman ini (sampai user menekan Reset).
        try {
            const saved = JSON.parse(localStorage.getItem('filters:monitoring-daily') || 'null');
            if (saved) Object.assign(this.f, saved);
        } catch (e) {}
        this.load();
    },
    isFiltered() { return JSON.stringify(this.f) !== JSON.stringify(this.initial); },
    reset() { this.f = { ...this.initial }; this.load(); },
    num: (v, d = 0) => fmt(v, d),
    shortDate(s) {
        if (!s) return '';
        const [y, m, d] = s.split('-');
        return `${d}-${m}-${y}`;
    },
    groupShort() { return this.d ? (this.d.filters.group || 'semua mesin') : ''; },

    async load() {
        try {
            if (this.isFiltered()) localStorage.setItem('filters:monitoring-daily', JSON.stringify(this.f));
            else localStorage.removeItem('filters:monitoring-daily');
        } catch (e) {}
        const id = ++this.reqId;
        this.loading = true;
        const qs = new URLSearchParams(Object.entries(this.f).filter(([, v]) => v !== '' && v !== null));
        try {
            const res = await fetch(cfg.endpoint + '?' + qs, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (id !== this.reqId) return;
            this.d = data;
            this.f.date = data.filters.date;
            this.$nextTick(() => this.renderAll());
        } finally {
            if (id === this.reqId) this.loading = false;
        }
    },

    kpis() {
        const k = this.d?.kpi || {}, c = this.d?.kpi_change || {};
        const has = (v) => v !== null && v !== undefined;
        return [
            { key: 'pc', label: 'Total PC', value: fmt(k.total_pc), change: c.total_pc ?? null, sub: 'vs kemarin',
                info: '<b>Total PC</b> = Hasil OK + Reject + Hasil NC semua mesin.' },
            { key: 'eff', label: 'Efficiency', value: has(k.efficiency) ? fmt(k.efficiency, 0) + '%' : '-', change: c.efficiency ?? null, sub: 'Act ÷ Std',
                info: '<b>Efficiency</b> = Total pcs ÷ Std pcs × 100.<br><b>Std pcs</b> = Std output (pcs/jam) × Waktu kerja (jam). PRO tanpa Std output tidak ikut dihitung.' },
            { key: 'prod', label: 'Productivity', value: has(k.productivity) ? fmt(k.productivity, 0) + '%' : '-', change: c.productivity ?? null,
                sub: k.planned_qty ? `plan ${short(k.planned_qty)}` : 'belum ada planning',
                info: '<b>Productivity</b> = Total PC ÷ jumlah Qty planning × 100.' },
        ];
    },

    draw(id, options) {
        const el = document.getElementById(id);
        if (!el) return;
        if (this.charts[id]) this.charts[id].destroy();
        el.innerHTML = '';
        this.charts[id] = new ApexCharts(el, options);
        this.charts[id].render();
    },
    empty(id, text = 'Belum ada data Form Rekapan karu', h = 200) {
        if (this.charts[id]) { this.charts[id].destroy(); delete this.charts[id]; }
        const el = document.getElementById(id);
        if (el) el.innerHTML = `<div class="grid place-items-center text-[13px]" style="height:${h}px;color:var(--md-muted)">${text}</div>`;
    },

    renderAll() {
        if (!this.d) return;
        this.renderMonth();
        this.renderLoss();
        this.renderProduction();
        this.renderEfficiency();
        this.renderNg();
    },

    /* 3. Produksi harian 1 bulan + segitiga hijau / merah vs target harian di bawah tanggal */
    renderMonth() {
        const t = boardTheme(), days = this.d.month.days;
        if (!days.some(r => r.pcs > 0)) return this.empty('ch-md-month');
        const mark = (r) => r.pcs <= 0 || !r.target ? '' : (r.pcs >= r.target ? '▲' : '▼');
        const markColor = (r) => r.pcs <= 0 || !r.target ? t.muted : (r.pcs >= r.target ? t.good : t.bad);
        this.draw('ch-md-month', base(t, {
            chart: { type: 'line', height: 210, toolbar: { show: false } },
            series: [{ name: 'Produksi', data: days.map(r => r.pcs) }],
            colors: [t.teal],
            stroke: { width: 2, curve: 'straight' },
            markers: { size: 3, strokeWidth: 0, hover: { size: 5 } },
            dataLabels: { enabled: true, offsetY: -4, formatter: v => v > 0 ? short(v) : '', style: { fontSize: '8.5px', fontWeight: 500, colors: [t.ink] }, background: { enabled: false } },
            xaxis: { categories: days.map(r => [String(r.day), mark(r)]), axisBorder: { color: t.axis }, axisTicks: { show: false }, tooltip: { enabled: false },
                labels: { rotate: 0, hideOverlappingLabels: false, style: { fontSize: '9px', colors: days.map(markColor) } } },
            yaxis: { min: 0, tickAmount: 3, labels: { formatter: v => short(v), style: { fontSize: '10px' } } },
            grid: { borderColor: t.grid, padding: { left: 0, right: 4, top: 0 } },
            legend: { show: false },
            tooltip: { theme: t.mode, y: { formatter: (v, o) => {
                const r = days[o.dataPointIndex];
                return `${fmt(v)} pcs` + (r.target ? ` · target ${fmt(r.target)}` : '');
            } } },
        }));
    },

    /* 4. Stacked bar menit Set Up & Downtime per tanggal */
    renderLoss() {
        const t = boardTheme(), days = this.d.month.days;
        if (!days.some(r => r.setup + r.downtime > 0)) return this.empty('ch-md-loss', 'Belum ada Set Up / Downtime');
        this.draw('ch-md-loss', base(t, {
            chart: { type: 'bar', height: 210, stacked: true, toolbar: { show: false } },
            series: [{ name: 'Set up', data: days.map(r => r.setup) }, { name: 'Downtime', data: days.map(r => r.downtime) }],
            colors: [t.gold, t.bad],
            plotOptions: { bar: { columnWidth: '70%', borderRadius: 2, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            xaxis: { categories: days.map(r => String(r.day)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: 0, hideOverlappingLabels: true, style: { fontSize: '9px' } } },
            yaxis: { labels: { formatter: v => fmt(v), style: { fontSize: '10px' } } },
            grid: { borderColor: t.grid, padding: { left: 0, right: 4, top: 0 } },
            legend: { position: 'top', horizontalAlign: 'right', fontSize: '11px', labels: { colors: t.ink2 }, markers: { size: 4 } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => fmt(v) + ' mnt' } },
        }));
    },

    /* 5. Bar total pcs per mesin + garis Cutting Time % dan ET & SU Rate % (sumbu kanan) */
    renderProduction() {
        const t = boardTheme(), rows = this.d.machines;
        if (!rows.length) return this.empty('ch-md-production', undefined, 300);
        const maxPcs = niceCeil(Math.max(1, ...rows.map(r => r.pcs)) * 1.15);
        this.draw('ch-md-production', base(t, {
            chart: { type: 'line', height: 310, toolbar: { show: false } },
            series: [
                { name: 'Total cutting pcs', type: 'column', data: rows.map(r => r.pcs) },
                { name: 'Cutting time %', type: 'line', data: rows.map(r => r.cutting_pct) },
                { name: 'ET & SU rate %', type: 'line', data: rows.map(r => r.loss_pct) },
            ],
            colors: [t.teal, t.blue, t.gold],
            stroke: { width: [0, 2.5, 2.5], curve: 'straight' },
            markers: { size: [0, 4, 4], strokeWidth: 0 },
            plotOptions: { bar: { columnWidth: '55%', borderRadius: 3, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, offsetY: -6, formatter: (v, o) => o.seriesIndex === 0 ? fmt(v) : fmt(v, 0) + '%',
                style: { fontSize: '9.5px', fontWeight: 600, colors: [t.ink, t.blue, t.gold] }, background: { enabled: false } },
            xaxis: { categories: rows.map(r => r.code), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, rotateAlways: rows.length > 8, style: { fontSize: '10px' } } },
            yaxis: [
                { seriesName: 'Total cutting pcs', min: 0, max: maxPcs, tickAmount: 4, labels: { formatter: v => short(v), style: { fontSize: '10px' } } },
                { seriesName: 'Cutting time %', opposite: true, min: 0, max: 100, tickAmount: 4, labels: { formatter: v => fmt(v, 0) + '%', style: { fontSize: '10px' } } },
                { seriesName: 'Cutting time %', show: false, min: 0, max: 100 },
            ],
            legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '11px', labels: { colors: t.ink2 }, markers: { size: 4 } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: (v, o) => o.seriesIndex === 0 ? fmt(v) + ' pcs' : fmt(v, 1) + '%' } },
        }));
    },

    /*
     * 6. Actual vs Planning (pcs, batang) per mesin + garis Efficiency % (sumbu kanan).
     * Planning = qty planning Form Rekapan; efficiency = actual ÷ std output (titik hijau ≥ target, merah < target).
     */
    renderEfficiency() {
        const t = boardTheme(), rows = this.d.machines;
        if (!rows.length) return this.empty('ch-md-eff', undefined, 300);
        const pcsMax = niceCeil(Math.max(1, ...rows.map(r => Math.max(r.pcs, r.planned_qty || 0))) * 1.15);
        const effMax = niceCeil(Math.max(100, ...rows.map(r => r.efficiency ?? 0)) * 1.12);
        const effColor = (r) => r.efficiency === null ? t.muted : (r.efficiency >= r.target_efficiency ? t.good : t.bad);
        this.draw('ch-md-eff', base(t, {
            chart: { type: 'line', height: 310, toolbar: { show: false } },
            series: [
                { name: 'Actual', type: 'column', data: rows.map(r => r.pcs) },
                { name: 'Planning', type: 'column', data: rows.map(r => r.planned_qty || null) },
                { name: 'Efficiency', type: 'line', data: rows.map(r => r.efficiency) },
            ],
            colors: [t.teal, t.blue, t.gold],
            stroke: { width: [0, 0, 2], curve: 'straight' },
            markers: { size: [0, 0, 5], strokeWidth: 2, strokeColors: t.surface, hover: { size: 7 },
                discrete: rows.map((r, i) => ({ seriesIndex: 2, dataPointIndex: i, fillColor: effColor(r), strokeColor: t.surface, size: 5 })) },
            plotOptions: { bar: { columnWidth: '70%', borderRadius: 3, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, enabledOnSeries: [0, 1, 2], offsetY: -6,
                formatter: (v, o) => v === null || v === undefined ? '' : (o.seriesIndex === 2 ? fmt(v, 0) + '%' : short(v)),
                style: { fontSize: '9px', fontWeight: 600, colors: [t.ink, t.ink2, t.gold] }, background: { enabled: false } },
            xaxis: { categories: rows.map(r => r.code), axisBorder: { color: t.axis }, axisTicks: { show: false }, tooltip: { enabled: false },
                labels: { rotate: -45, rotateAlways: rows.length > 8, style: { fontSize: '10px' } } },
            yaxis: [
                { seriesName: 'Actual', min: 0, max: pcsMax, tickAmount: 4, labels: { formatter: v => short(v), style: { fontSize: '10px' } } },
                { seriesName: 'Actual', show: false, min: 0, max: pcsMax },
                { seriesName: 'Efficiency', opposite: true, min: 0, max: effMax, tickAmount: 4, labels: { formatter: v => fmt(v, 0) + '%', style: { fontSize: '10px' } } },
            ],
            legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '11px', labels: { colors: t.ink2 }, markers: { size: 4 } },
            tooltip: { theme: t.mode, shared: true, intersect: false, custom: ({ dataPointIndex: i }) => {
                const r = rows[i];
                return `<div class="px-3 py-2 text-[12px]"><div class="font-semibold mb-0.5">${r.code}</div>`
                    + `Actual ${fmt(r.pcs)} pcs · Planning ${r.planned_qty ? fmt(r.planned_qty) + ' pcs' : 'belum ada'}`
                    + (r.productivity !== null && r.productivity !== undefined ? ` (${fmt(r.productivity, 0)}%)` : '') + '<br>'
                    + `Efficiency ${r.efficiency === null ? '-' : fmt(r.efficiency, 1) + '%'} (target ${fmt(r.target_efficiency, 0)}%)<br>`
                    + `NG rate ${fmt(r.ng_rate, 2)}% · ${fmt(r.ng)} pcs</div>`;
            } },
        }));
    },

    /* 7. NG tertinggi per mesin (NG rate %), urut terbesar */
    renderNg() {
        const t = boardTheme(), rows = this.d.ng_top;
        if (!rows.length) return this.empty('ch-md-ng', 'Tidak ada NG pada tanggal ini', 300);
        this.draw('ch-md-ng', base(t, {
            chart: { type: 'bar', height: 310, toolbar: { show: false } },
            series: [{ name: 'NG rate', data: rows.map(r => r.rate) }],
            colors: [t.pink],
            plotOptions: { bar: { columnWidth: '55%', borderRadius: 3, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, offsetY: -16, formatter: v => fmt(v, 1) + '%', style: { fontSize: '10px', fontWeight: 600, colors: [t.ink] } },
            xaxis: { categories: rows.map(r => r.code), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, rotateAlways: rows.length > 5, style: { fontSize: '10px' } } },
            yaxis: { min: 0, max: niceCeil(Math.max(...rows.map(r => r.rate)) * 1.25), tickAmount: 4, labels: { formatter: v => fmt(v, 1) + '%', style: { fontSize: '10px' } } },
            legend: { show: false },
            tooltip: { theme: t.mode, y: { formatter: (v, o) => `${fmt(v, 2)}% · ${fmt(rows[o.dataPointIndex].ng)} dari ${fmt(rows[o.dataPointIndex].pcs)} pcs` } },
        }));
    },
}));

/* ------------------------------------------------------------------ ⓘ rumus & sumber data (komponen <x-info-tip>) */
Alpine.data('infoTip', () => ({
    open: false,
    style: '',
    toggle() {
        if (this.open) { this.open = false; return; }
        const r = this.$refs.btn.getBoundingClientRect();
        const w = Math.min(360, window.innerWidth - 32);
        const left = Math.min(Math.max(16, r.left), window.innerWidth - 16 - w);
        const below = window.innerHeight - r.bottom;
        // tampil di bawah tombol; jika ruang di bawah sempit, tampil di atasnya
        this.style = below >= 220 || below >= r.top
            ? `left:${left}px;top:${r.bottom + 6}px;width:${w}px;max-height:${below - 22}px`
            : `left:${left}px;bottom:${window.innerHeight - r.top + 6}px;width:${w}px;max-height:${r.top - 22}px`;
        this.open = true;
    },
    init() {
        // panel ber-posisi tetap: tutup saat halaman / tabel digulir supaya tidak "melayang" terpisah dari tombolnya
        const close = (e) => { if (this.open && !e?.target?.closest?.('.info-pop')) this.open = false; };
        window.addEventListener('scroll', close, { passive: true, capture: true });
        window.addEventListener('resize', close);
    },
}));

/* ------------------------------------------------------------------ detail laporan (halaman data produksi) */
Alpine.data('reportDetail', () => ({
    open: false,
    loading: false,
    r: null,
    async show(url) {
        this.open = true;
        this.loading = true;
        this.r = null;
        try {
            this.r = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
        } finally {
            this.loading = false;
        }
    },
    fmt: (v) => nf.format(v),
}));

/* ------------------------------------------------------------------ kartu karyawan (klik nama pengguna) */
Alpine.data('employeeCard', () => ({
    show: false,
    loading: false,
    e: null,
    async open(url) {
        this.show = true;
        this.loading = true;
        this.e = null;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (res.ok) this.e = await res.json();
        } finally {
            this.loading = false;
        }
    },
    initials() { return (this.e?.name || '?').split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase(); },
}));

/* ------------------------------------------------------------------ form karu (HP) */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
/* Jam sekarang dalam WIB (Asia/Jakarta), tidak tergantung pengaturan zona waktu HP */
const wibHour = () => {
    try {
        return Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Jakarta', hour: '2-digit', hourCycle: 'h23' }).format(new Date()));
    } catch (e) {
        return new Date().getHours();
    }
};
/* Shift karu: 1 = 07–18, 2 = 18–07. Lewat tengah malam masih shift 2 dengan tanggal hari sebelumnya. */
const guessShift = () => { const h = wibHour(); return h >= 7 && h < 18 ? 1 : 2; };
const guessFormDate = (today) => {
    if (wibHour() >= 7) return today;
    const d = new Date(today + 'T12:00:00');
    d.setDate(d.getDate() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/* Perkecil foto di HP sebelum diunggah (maks 1600 px, JPEG) supaya cepat & hemat penyimpanan. */
async function compressImage(file, max = 1600, quality = 0.82) {
    if (!file.type.startsWith('image/')) return file;
    try {
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bmp.width * scale);
        canvas.height = Math.round(bmp.height * scale);
        canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', quality));
        return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) {
        return file;
    }
}

function karuFormBase(cfg) {
    return {
        cfg: cfg.config,
        errors: {},
        saving: false,
        uploading: 0,
        message: '',
        err(path) { return this.errors[path]?.[0] || ''; },
        hasErr(prefix) { return Object.keys(this.errors).some(k => k === prefix || k.startsWith(prefix + '.')); },
        machineCode(id) { return this.cfg.machines.find(m => String(m.id) === String(id))?.code || ''; },
        opts(group) { return this.cfg.options[group] || []; },
        /* Visual OK = centang hijau, NG = silang merah */
        visualTone(label) { return { OK: 'tick-ok', NG: 'tick-ng' }[String(label || '').trim().toUpperCase()] || ''; },
        /* Area awal form baru = area tugas karu (tetap bisa diganti; semua karu bisa input di semua area & mesin) */
        defaultArea() { return cfg.karu?.defaultAreaId ? String(cfg.karu.defaultAreaId) : ''; },

        /* key: daftar foto di baris itu (photos = evidence umum, pipe_photos = foto visual pipa) */
        async addPhotos(row, files, key = 'photos') {
            for (const f of Array.from(files || [])) {
                this.uploading++;
                row[key].push({ id: null, url: URL.createObjectURL(f), uploading: true, error: '' });
                const tmp = row[key][row[key].length - 1]; // ambil versi reaktif (proxy) supaya tampilan ikut berubah
                try {
                    const file = await compressImage(f);
                    // Normalnya foto sudah diperkecil (±200–500 KB). Bila HP gagal memperkecil, jangan kirim file raksasa.
                    if (file.size > 8 * 1024 * 1024) throw new Error('Foto terlalu besar (maks 8 MB). Ambil ulang dengan resolusi lebih kecil.');
                    const body = new FormData();
                    body.append('file', file);
                    const res = await fetch(cfg.uploadUrl, { method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.errors?.file?.[0] || data.message || 'Gagal mengunggah');
                    Object.assign(tmp, { id: data.id, serverUrl: data.url, uploading: false });
                } catch (e) {
                    Object.assign(tmp, { uploading: false, error: e.message });
                } finally {
                    this.uploading--;
                }
            }
        },
        removePhoto(row, i, key = 'photos') { row[key].splice(i, 1); },

        clean(v) { return v === '' || v === undefined ? null : v; },
        optVal(v) { return v === 'other' || v === '' || v === undefined ? null : Number(v); },

        /*
         * Draf otomatis di perangkat: isian yang sudah diketik tidak hilang walau gagal simpan, sesi login habis,
         * koneksi putus atau halaman termuat ulang. Draf dihapus setelah form berhasil disimpan.
         */
        draftKey: 'draft:' + location.pathname,
        draftRestored: false,
        withDraft(fresh) {
            try {
                const d = JSON.parse(localStorage.getItem(this.draftKey) || 'null');
                if (d?.f && Date.now() - d.at < 14 * 864e5) { this.draftRestored = true; return d.f; }
            } catch (e) { /* penyimpanan tidak tersedia */ }
            return fresh;
        },
        watchDraft() {
            let timer;
            this.$watch('f', () => { clearTimeout(timer); timer = setTimeout(() => this.saveDraft(), 400); });
        },
        saveDraft() {
            const f = JSON.parse(JSON.stringify(this.f, (k, v) => (k === 'photos' || k === 'pipe_photos') && Array.isArray(v)
                ? v.filter(p => p.id).map(p => ({ id: p.id, url: p.serverUrl || p.url }))
                : v));
            try { localStorage.setItem(this.draftKey, JSON.stringify({ at: Date.now(), f })); } catch (e) { /* penuh / diblokir */ }
        },
        clearDraft() { try { localStorage.removeItem(this.draftKey); } catch (e) { /* abaikan */ } },
        discardDraft() {
            if (!confirm('Buang isian yang belum tersimpan dan mulai dari awal?')) return;
            this.clearDraft();
            location.reload();
        },

        async submit(payload) {
            if (this.uploading) { this.message = 'Tunggu, foto masih diunggah…'; return; }
            this.saving = true;
            this.errors = {};
            this.message = '';
            try {
                const res = await fetch(cfg.action, {
                    method: cfg.method,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify(payload),
                });
                const data = await res.json().catch(() => ({}));
                if (res.status === 422) {
                    this.errors = data.errors || {};
                    this.message = 'Ada isian yang perlu diperbaiki (ditandai merah).';
                    // ambil yang terlihat: form evaluasi punya tampilan kartu (HP) dan tabel (layar lebar)
                    this.$nextTick(() => [...document.querySelectorAll('[data-error="1"]')].find(el => el.offsetParent !== null)
                        ?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                    return;
                }
                if (res.status === 419 || res.status === 401) {
                    this.message = 'Sesi login habis. Isian Anda aman tersimpan di perangkat ini — muat ulang halaman / login lagi, lalu tekan Simpan.';
                    return;
                }
                if (!res.ok) { this.message = data.message || 'Gagal menyimpan. Isian tetap ada, coba lagi.'; return; }
                this.clearDraft();
                window.location = data.redirect;
            } catch (e) {
                this.message = 'Koneksi terputus. Isian tetap ada, data belum tersimpan — coba Simpan lagi.';
            } finally {
                this.saving = false;
            }
        },
    };
}

Alpine.data('productionForm', (cfg) => ({
    ...karuFormBase(cfg),
    f: null,
    init() {
        const p = cfg.payload;
        this.f = this.withDraft({
            form_date: p?.form_date || guessFormDate(cfg.today),
            shift: p?.shift || guessShift(),
            area_id: p?.area_id || this.defaultArea(),
            substitute_for_id: p?.substitute_for_id || '',
            items: (p?.items || []).map(it => ({ ...this.newItem(), ...it, open: false })),
        });
        this.f.items = this.f.items.map(it => ({ ...this.newItem(), ...it }));
        if (!this.f.items.length) this.addItem();
        this.watchDraft();
    },
    /* Satu baris = satu PRO di satu mesin. PRO baru membawa mesin dari baris sebelumnya (ganti PRO di mesin yang sama). */
    newItem(prev = null) {
        return { machine_type: prev?.machine_type || '', machine_id: prev?.machine_id || '', order_no: '', dimension: '', planned_start: '', planned_qty: '',
            working_minutes: '', cutting_minutes: '', std_output: '', act_output: '',
            ok_qty: '', second_grade_qty: '', reject_reason: '', nc_qty: '', note: '', problems: [], photos: [], open: true };
    },
    addItem() {
        const prev = this.f.items[this.f.items.length - 1];
        this.f.items.forEach(i => i.open = false);
        this.f.items.push(this.newItem(prev));
        this.$nextTick(() => document.getElementById('item-' + (this.f.items.length - 1))?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    removeItem(i) { if (confirm('Hapus PRO #' + (i + 1) + ' dari form?')) this.f.items.splice(i, 1); },
    /* Semua mesin (tidak dibatasi area), cukup disaring per jenis mesin */
    machinesFor(it) {
        return this.cfg.machines.filter(m => !it.machine_type || m.type === it.machine_type);
    },
    hasReject(it) { return Number(it.second_grade_qty) > 0; },
    /*
     * Selisih waktu kerja − cutting time = jatah menit untuk Set Up + Bongkar + Downtime.
     * null bila waktu kerja / cutting time belum diisi.
     */
    spareMinutes(it) {
        if (it.working_minutes === '' || it.working_minutes === null || it.cutting_minutes === '' || it.cutting_minutes === null) return null;
        return Number(it.working_minutes) - Number(it.cutting_minutes);
    },
    usedMinutes(it) { return it.problems.reduce((a, p) => a + (Number(p.minutes) || 0), 0); },
    overMinutes(it) {
        const spare = this.spareMinutes(it);
        return spare !== null && spare >= 0 && this.usedMinutes(it) > spare ? this.usedMinutes(it) - spare : 0;
    },
    /* Pesan jatah menit di bawah judul Problem; kosong bila belum bisa dihitung */
    budgetText(it) {
        const spare = this.spareMinutes(it);
        if (spare === null) return 'Isi waktu kerja & cutting time dulu: selisihnya jadi jatah menit Set Up / Bongkar / Downtime.';
        if (spare < 0) return 'Cutting time lebih besar dari waktu kerja.';
        const used = this.usedMinutes(it), over = this.overMinutes(it);
        if (over) return `Kelebihan ${over} mnt: total ${used} dari jatah ${spare} mnt. Kecilkan menit Set Up, Bongkar atau Downtime supaya pas.`;
        return `Terpakai ${used} dari jatah ${spare} mnt (waktu kerja − cutting time) · sisa ${spare - used} mnt`;
    },
    pickMachine(it) {
        const m = this.cfg.machines.find(x => String(x.id) === String(it.machine_id));
        if (m && it.machine_type !== m.type) { it.machine_type = m.type; this.fixProblems(it); }
    },
    changeType(it) {
        if (it.machine_id && !this.machinesFor(it).some(m => String(m.id) === String(it.machine_id))) it.machine_id = '';
        this.fixProblems(it);
    },
    problemOptions(it, kind) {
        return kind === 'dt' ? this.opts('dt').filter(o => !o.type || o.type === (it.machine_type || 'umum')) : this.opts(kind);
    },
    kindLabel(kind) { return { setup: 'SET UP', bongkar: 'BONGKAR', dt: 'DT' }[kind] || 'DT'; },
    problemLabel(it, p) {
        if (p.option_id === 'other') return 'Lainnya (isi manual)';
        return this.problemOptions(it, p.kind).find(o => String(o.id) === String(p.option_id))?.label || '';
    },
    /* Cari keterangan: semua kata yang diketik harus ada (tidak peduli huruf besar / urutan). */
    searchOptions(it, kind, q) {
        const words = String(q || '').toLowerCase().split(/\s+/).filter(Boolean);
        return this.problemOptions(it, kind).filter(o => words.every(w => o.label.toLowerCase().includes(w)));
    },
    fixProblems(it) {
        it.problems.forEach(p => {
            if (p.option_id && p.option_id !== 'other' && !this.problemOptions(it, p.kind).some(o => String(o.id) === String(p.option_id))) p.option_id = '';
        });
    },
    addProblem(it, kind) { it.problems.push({ kind, option_id: '', other_text: '', minutes: '' }); },
    /* Placeholder menit: tunjukkan sisa jatah supaya karu tahu maksimal yang bisa diisi */
    minutesHint(it, p) {
        const spare = this.spareMinutes(it);
        if (spare === null || spare < 0) return 'Menit *';
        const left = spare - this.usedMinutes(it) + (Number(p.minutes) || 0);
        return 'maks ' + Math.max(0, left);
    },
    totalMinutes(it, kind) { return it.problems.filter(p => p.kind === kind).reduce((a, p) => a + (+p.minutes || 0), 0); },
    summary(it) {
        const parts = [it.order_no ? 'PRO ' + it.order_no : 'PRO belum diisi'];
        if (it.ok_qty !== '' && it.ok_qty !== null) parts.push(`OK ${fmt(+it.ok_qty)}`);
        const dt = this.totalMinutes(it, 'dt'), su = this.totalMinutes(it, 'setup'), bk = this.totalMinutes(it, 'bongkar');
        if (dt) parts.push(`DT ${dt} mnt`);
        if (su) parts.push(`Set up ${su} mnt`);
        if (bk) parts.push(`Bongkar ${bk} mnt`);
        if (it.photos.length) parts.push(`${it.photos.length} foto`);
        return parts.join(' · ');
    },
    save() {
        // cek jatah menit dulu di perangkat (isian tidak direset); server tetap memeriksa ulang
        const over = this.f.items.findIndex(it => this.overMinutes(it) > 0);
        if (over >= 0) {
            this.f.items[over].open = true;
            this.message = `PRO #${over + 1}: menit Set Up + Bongkar + Downtime melebihi selisih waktu kerja − cutting time. Kecilkan salah satunya.`;
            this.$nextTick(() => document.getElementById('item-' + over)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            return;
        }
        this.submit({
            form_date: this.f.form_date, shift: Number(this.f.shift), area_id: this.clean(this.f.area_id),
            substitute_for_id: this.clean(this.f.substitute_for_id),
            items: this.f.items.map(it => ({
                machine_type: it.machine_type || 'umum', machine_id: this.clean(it.machine_id), order_no: this.clean(it.order_no), dimension: this.clean(it.dimension),
                planned_start: this.clean(it.planned_start), planned_qty: this.clean(it.planned_qty),
                working_minutes: this.clean(it.working_minutes), cutting_minutes: this.clean(it.cutting_minutes),
                std_output: this.clean(it.std_output), act_output: this.clean(it.act_output), ok_qty: this.clean(it.ok_qty),
                second_grade_qty: this.clean(it.second_grade_qty), reject_reason: this.hasReject(it) ? this.clean((it.reject_reason || '').trim()) : null,
                nc_qty: this.clean(it.nc_qty), note: this.clean(it.note),
                problems: it.problems.map(p => ({ kind: p.kind, option_id: this.optVal(p.option_id),
                    other_text: p.option_id === 'other' ? this.clean(p.other_text) : null, minutes: this.clean(p.minutes) })),
                attachments: it.photos.filter(x => x.id).map(x => x.id),
            })),
        }).then(() => this.f.items.forEach((it, i) => { if (this.hasErr('items.' + i)) it.open = true; }));
    },
}));

Alpine.data('evaluationForm', (cfg) => ({
    ...karuFormBase(cfg),
    f: null,
    init() {
        const p = cfg.payload;
        this.f = this.withDraft({
            form_date: p?.form_date || cfg.defaults?.form_date || guessFormDate(cfg.today),
            shift: p?.shift || guessShift(),
            area_id: p?.area_id || cfg.defaults?.area_id || this.defaultArea(),
            substitute_for_id: p?.substitute_for_id || '',
            checks: (p?.checks || []).map(c => ({ ...this.newCheck(), ...c })),
        });
        this.f.checks = this.f.checks.map(c => this.upgradeCheck({ ...this.newCheck(), ...c }));
        if (!this.f.checks.length) this.addCheck();
        this.watchDraft();
    },
    /* Jam cek tidak diketik: baris lama memakai jam tersimpan, baris baru diisi jam saat disimpan (WIB, di server). */
    newCheck(prev = null) {
        return { id: null, check_time: '', machine_id: prev?.machine_id || '', customer: prev?.customer || '', dimension: prev?.dimension || '',
            od_mm: '', d1: '', d2: '', d3: '', d4: '', thickness_mm: '', ib_height_mm: '', length_mm: '', p1: '', p2: '', p3: '',
            jig_ids: [], jig_sizes: {}, jig_other_on: false, jig_other: '', visual_option_id: '', visual_other: '',
            compliance_ids: [], compliance_other_on: false, compliance_other: '',
            actual_cutting: '', problem: '', photos: [], pipe_photos: [], pipe_photo_url: '' };
    },
    /* Draf lama (jig satu pilihan, angka bertitik) disesuaikan ke bentuk baru */
    upgradeCheck(c) {
        if (c.jig_option_id && !c.jig_ids.length) {
            if (c.jig_option_id === 'other') c.jig_other_on = true; else c.jig_ids = [c.jig_option_id];
        }
        delete c.jig_option_id;
        if (!c.jig_sizes || Array.isArray(c.jig_sizes)) c.jig_sizes = {};
        ['od_mm', 'd1', 'd2', 'd3', 'd4', 'thickness_mm', 'ib_height_mm', 'length_mm', 'p1', 'p2', 'p3'].forEach(k => {
            if (typeof c[k] === 'number') c[k] = String(c[k]).replace('.', ',');
            if (c[k] === null || c[k] === undefined) c[k] = '';
        });
        return c;
    },
    timeLabel(c) { return c.id && c.check_time ? c.check_time : 'Otomatis'; },
    /*
     * Angka ukur: hanya angka & satu koma (titik dianggap koma), maksimal 2 angka di belakang koma.
     * Tinggi IB boleh diawali minus.
     */
    cleanDecimal(v, allowMinus = false) {
        let s = String(v ?? '').replace(/\./g, ',').replace(allowMinus ? /[^\d,-]/g : /[^\d,]/g, '');
        const neg = allowMinus && s.startsWith('-');
        s = s.replace(/-/g, '');
        const [int, ...rest] = s.split(',');
        s = rest.length ? int + ',' + rest.join('').slice(0, 2) : int;
        return (neg ? '-' : '') + s;
    },
    /* Jig: boleh centang lebih dari satu; JIG In / JIG Out perlu ukuran jig */
    hasJig(c, id) { return c.jig_ids.map(String).includes(String(id)); },
    jigNeedsSize(id) { return (this.cfg.jigSizeIds || []).map(String).includes(String(id)); },
    toggleJig(c, id, on) {
        const rest = c.jig_ids.filter(x => String(x) !== String(id));
        c.jig_ids = on ? [...rest, id] : rest;
        if (on && this.jigNeedsSize(id) && c.jig_sizes[id] === undefined) c.jig_sizes[id] = '';
    },
    /* "Pengecekan visual pipa" dicentang → muncul unggah foto / link gambar (wajib salah satu). */
    hasPipe(c) { return !!this.cfg.visualPipeId && c.compliance_ids.map(String).includes(String(this.cfg.visualPipeId)); },
    /* Pengecekan baru otomatis membawa mesin, customer & dimensi dari pengecekan sebelumnya (bisa diganti). */
    addCheck() {
        const prev = this.f.checks[this.f.checks.length - 1];
        this.f.checks.push(this.newCheck(prev));
        const n = this.f.checks.length - 1;
        this.$nextTick(() => [document.getElementById('check-' + n), document.getElementById('check-row-' + n)].find(el => el?.offsetParent)
            ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    },
    toggleCompliance(c, id, on) {
        const rest = c.compliance_ids.filter(x => String(x) !== String(id));
        c.compliance_ids = on ? [...rest, id] : rest;
    },
    removeCheck(i) { if (confirm('Hapus baris pengecekan #' + (i + 1) + '?')) this.f.checks.splice(i, 1); },
    /* Semua mesin, tidak dibatasi area */
    machinesFor() { return this.cfg.machines; },
    optLabel(group, id) { return this.opts(group).find(o => String(o.id) === String(id))?.label || (id === 'other' ? 'Lainnya' : ''); },
    summary(c) {
        const parts = [c.id ? c.check_time : '', this.machineCode(c.machine_id) || 'Mesin belum dipilih'];
        const v = this.optLabel('visual', c.visual_option_id);
        if (v) parts.push('Visual ' + v);
        if (c.photos.length) parts.push(`${c.photos.length} foto`);
        return parts.filter(Boolean).join(' · ');
    },
    save() {
        const n = (v) => this.clean(v);
        const dec = (v) => { const s = String(v ?? '').trim().replace(',', '.'); return s === '' || s === '-' ? null : s; };
        this.submit({
            form_date: this.f.form_date, shift: Number(this.f.shift), area_id: n(this.f.area_id),
            substitute_for_id: n(this.f.substitute_for_id),
            checks: this.f.checks.map(c => ({
                id: c.id || null, machine_id: n(c.machine_id), customer: n(c.customer), dimension: n(c.dimension),
                // planning lama (sekarang di Form Rekapan) hanya dikirim ulang bila ada, supaya tidak hilang saat diedit
                planned_start: n(c.planned_start), planned_qty: n(c.planned_qty),
                od_mm: dec(c.od_mm), d1: dec(c.d1), d2: dec(c.d2), d3: dec(c.d3), d4: dec(c.d4), thickness_mm: dec(c.thickness_mm),
                ib_height_mm: dec(c.ib_height_mm), length_mm: dec(c.length_mm), p1: dec(c.p1), p2: dec(c.p2), p3: dec(c.p3),
                jig_option_ids: c.jig_ids.map(Number),
                jig_sizes: Object.fromEntries(c.jig_ids.filter(id => this.jigNeedsSize(id)).map(id => [id, (c.jig_sizes[id] || '').trim()])),
                jig_other: c.jig_other_on ? n((c.jig_other || '').trim()) : null,
                visual_option_id: this.optVal(c.visual_option_id), visual_other: c.visual_option_id === 'other' ? n(c.visual_other) : null,
                compliance_option_ids: c.compliance_ids.map(Number), compliance_other: c.compliance_other_on ? n(c.compliance_other) : null,
                actual_cutting: n(c.actual_cutting), problem: n(c.problem),
                attachments: c.photos.filter(x => x.id).map(x => x.id),
                pipe_attachments: this.hasPipe(c) ? c.pipe_photos.filter(x => x.id).map(x => x.id) : [],
                pipe_photo_url: this.hasPipe(c) ? n((c.pipe_photo_url || '').trim()) : null,
            })),
        });
    },
}));

/* Galeri foto sederhana (halaman detail form) */
Alpine.data('gallery', () => ({
    src: null,
    open(url) { this.src = url; },
}));

Alpine.start();
