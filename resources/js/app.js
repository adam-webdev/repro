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
    f: { from: cfg.filters.from, to: cfg.filters.to, period: cfg.filters.period, shift: '', area_id: '', building: '', machine_id: '' },
    initial: null,
    d: null,
    loading: false,
    preset: '30d',
    prodMode: 'stack',
    charts: {},
    sort: { key: 'ok', dir: -1 },
    reqId: 0,
    dt: { open: false, loading: false, key: '', name: '', color: '', bucket: null, data: null, reqId: 0 },
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
        { key: 'achievement', label: 'Capaian', num: true }, { key: 'yield_sap', label: 'Yield', num: true },
    ],

    init() {
        this.initial = JSON.parse(JSON.stringify(this.f));
        window.addEventListener('theme-changed', () => this.renderAll());
        // Filter terakhir dipakai lagi saat kembali ke halaman ini (sampai user menekan Reset).
        const saved = this.loadSaved();
        if (saved) {
            Object.assign(this.f, saved.f);
            // area / mesin yang sudah tidak ada (mis. Area 04 digabung ke Area 03) kembali ke "Semua"
            if (this.f.area_id && !cfg.machines.some(m => String(m.area_id) === String(this.f.area_id))) this.f.area_id = '';
            if (this.f.machine_id && !cfg.machines.some(m => String(m.id) === String(this.f.machine_id))) this.f.machine_id = '';
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
    pct: (v) => (v === null || v === undefined) ? '-' : fmt(v, 1) + '%',
    fmtDate,
    fmtRange(r) { return r ? `${fmtDate(r[0])} – ${fmtDate(r[1])}` : ''; },
    periodLabel() { return { day: 'hari', week: 'minggu', month: 'bulan' }[this.f.period]; },
    badge(v, good, warn) { if (v === null) return 'chip-muted'; return v >= good ? 'chip-ok' : (v >= warn ? 'chip-warn' : 'chip-crit'); },
    isFiltered() { return JSON.stringify(this.f) !== JSON.stringify(this.initial); },
    machineOptions() {
        return cfg.machines.filter(m => (!this.f.area_id || String(m.area_id) === String(this.f.area_id)) && (!this.f.building || m.building === this.f.building));
    },
    reset() { this.f = JSON.parse(JSON.stringify(this.initial)); this.preset = '30d'; this.load(); },

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
        const qs = new URLSearchParams(Object.entries(this.f).filter(([, v]) => v !== '' && v !== null));
        try {
            const res = await fetch(cfg.endpoint + '?' + qs, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (id !== this.reqId) return; // abaikan respons lama
            this.d = data;
            this.f.period = data.filters.period;
            this.$nextTick(() => this.renderAll());
            if (this.dt.open) this.openDowntime(this.dt.key, null, false); // filter berubah: rincian ikut periode baru
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
            { key: 'ok', label: 'Hasil baik', hint: 'pcs', value: short(k.ok), sub: `${fmt(k.ok_kg / 1000, 1)} ton · ${fmt(k.reports)} laporan`, ...delta(k.ok, p.ok) },
            { key: 'yield', label: 'Yield', hint: '(Baik+NC) ÷ bahan', value: this.pct(k.yield_sap), sub: 'Sesuai kolom Yield di Excel', ...delta(k.yield_sap, p.yield_sap, true, true) },
            { key: 'nc', label: 'NC', hint: '% total', value: this.pct(k.nc_pct), sub: `${short(k.nc)} pcs`, ...delta(k.nc_pct, p.nc_pct, false, true) },
            { key: 'reject', label: 'Reject', hint: '% total', value: this.pct(k.reject_pct), sub: `${short(k.reject)} pcs`, ...delta(k.reject_pct, p.reject_pct, false, true) },
            { key: 'cut', label: 'Cutting time', hint: '% jam kerja', value: this.pct(k.cutting_pct), sub: `Capaian ${this.pct(k.achievement)}`, ...delta(k.cutting_pct, p.cutting_pct, true, true) },
            { key: 'dt', label: 'Downtime', hint: 'jam', value: fmt(k.downtime_hours, 0) + ' j', sub: `${k.machines || 0} mesin · ${k.days || 0} hari`, ...delta(k.downtime_hours, p.downtime_hours, false) },
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
        this.renderPareto('ch-downtime-pareto', this.d.downtime_pareto, 'hours', 'jam', 0);
        this.renderPareto('ch-defect-pareto', this.d.defect_pareto, 'qty', 'pcs', 0);
        this.renderNc();
        this.renderShift();
        this.renderArea();
        this.renderCuttingMachines();
        this.renderCustomers();
    },

    renderProduction() {
        const t = theme(), tr = this.d.trend;
        if (!tr.length) return this.empty('ch-production');
        const pctMode = this.prodMode === 'pct';
        this.draw('ch-production', base(t, {
            chart: { type: 'bar', height: 300, stacked: true, stackType: pctMode ? '100%' : 'normal' },
            series: [
                { name: 'Baik', data: tr.map(r => r.ok) },
                { name: 'NC', data: tr.map(r => r.nc) },
                { name: 'Reject', data: tr.map(r => r.reject) },
            ],
            colors: [t.series[0], t.series[1], t.series[7]], // Baik biru, NC oranye, Reject merah
            plotOptions: { bar: { columnWidth: tr.length > 20 ? '70%' : '50%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: tr.map(r => periodLabel(r.period, this.f.period)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } } },
            yaxis: { labels: { formatter: v => pctMode ? fmt(v, 0) + '%' : short(v) } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: (v, o) => {
                const r = tr[o.dataPointIndex]; const tot = r.ok + r.nc + r.reject;
                return `${fmt(v)} pcs (${fmt(tot ? v / tot * 100 : 0, 1)}%)`;
            } } },
        }));
    },

    renderYield() {
        const t = theme(), tr = this.d.trend;
        if (!tr.length) return this.empty('ch-yield');
        const vals = tr.flatMap(r => [r.yield_sap, r.cutting_pct]).filter(v => v !== null);
        this.draw('ch-yield', base(t, {
            chart: { type: 'line', height: 300 },
            series: [
                { name: 'Yield', data: tr.map(r => r.yield_sap) },
                { name: 'Cutting time', data: tr.map(r => r.cutting_pct) },
            ],
            colors: [t.series[0], t.series[2]],
            stroke: { width: 2, curve: 'smooth' },
            markers: { size: tr.length > 20 ? 0 : 4, strokeWidth: 2, strokeColors: t.surface, hover: { size: 6 } },
            xaxis: { categories: tr.map(r => periodLabel(r.period, this.f.period)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } }, tickAmount: Math.min(tr.length, 10), tooltip: { enabled: false } },
            yaxis: { min: Math.max(0, Math.floor(Math.min(...vals) / 5) * 5 - 5), max: Math.min(105, Math.ceil(Math.max(...vals) / 5) * 5 + 2),
                tickAmount: 5, labels: { formatter: v => fmt(v, 0) + '%' } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => v === null ? '-' : fmt(v, 2) + '%' } },
        }));
    },

    renderDowntimeTrend() {
        const t = theme(), dt = this.d.downtime_trend;
        if (!dt.periods.length) return this.empty('ch-downtime-trend');
        const colorOf = this.categoryColors(t);
        this.draw('ch-downtime-trend', base(t, {
            chart: { type: 'bar', height: 300, stacked: true, events: {
                dataPointSelection: (e, ctx, o) => this.openDowntime(dt.series[o.seriesIndex].key, dt.periods[o.dataPointIndex]),
                legendClick: (ctx, i) => this.openDowntime(dt.series[i].key, null),
            } },
            legend: { ...base(t).legend, onItemClick: { toggleDataSeries: false } },
            series: dt.series.map(s => ({ name: s.name, data: s.data })),
            colors: dt.series.map(s => colorOf[s.key]),
            plotOptions: { bar: { columnWidth: dt.periods.length > 20 ? '72%' : '50%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: dt.periods.map(p => periodLabel(p, this.f.period)), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } } },
            yaxis: { labels: { formatter: v => fmt(v, 0) + ' j' } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => fmt(v, 1) + ' jam' } },
        }));
    },

    categoryColors(t) {
        return { setup: t.series[3], downtime: t.series[0] }; // kelompok sesuai sheet DT Excel
    },

    /* Klik batang / donat Set Up & Downtime: tampilkan penyebab (kolom sheet DT), mesin & catatan keterangan. */
    async openDowntime(key, bucket = null, scroll = true) {
        const id = ++this.dt.reqId;
        Object.assign(this.dt, {
            open: true, loading: true, key, bucket, data: null,
            name: { setup: 'Set Up', downtime: 'Downtime' }[key] || key, color: this.categoryColors(theme())[key],
        });
        if (scroll) this.$nextTick(() => document.getElementById('dt-detail')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
        const qs = new URLSearchParams(Object.entries(this.f).filter(([, v]) => v !== '' && v !== null));
        qs.set('category', key);
        if (bucket) qs.set('bucket', bucket);
        try {
            const res = await fetch(cfg.detailEndpoint + '?' + qs, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (id === this.dt.reqId) this.dt.data = data;
        } finally {
            if (id === this.dt.reqId) this.dt.loading = false;
        }
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
                dataPointSelection: (e, ctx, o) => this.openDowntime(rows[o.dataPointIndex].key, null),
                legendClick: (ctx, i) => this.openDowntime(rows[i].key, null),
            } },
            series: rows.map(r => r.hours),
            labels: rows.map(r => r.name),
            colors: rows.map(r => colorOf[r.key]),
            stroke: { width: 2, colors: [t.surface] },
            legend: { position: 'bottom', fontSize: '12px', labels: { colors: t.ink2 }, markers: { size: 5, shape: 'circle' }, onItemClick: { toggleDataSeries: false } },
            plotOptions: { pie: { donut: { size: '68%', labels: { show: true,
                name: { fontSize: '12px', color: t.muted },
                value: { fontSize: '22px', fontWeight: 600, color: t.ink, formatter: v => fmt(+v, 0) + ' j' },
                total: { show: true, label: 'Total downtime', color: t.muted, fontSize: '12px', formatter: () => fmt(total, 0) + ' j' } } } } },
            tooltip: { theme: t.mode, y: { formatter: v => `${fmt(v, 1)} jam (${fmt(v / total * 100, 1)}%)` } },
        }));
    },

    renderPareto(id, rows, field, unit, dec) {
        const t = theme();
        if (!rows.length) return this.empty(id);
        this.draw(id, base(t, {
            chart: { type: 'bar', height: 340 },
            series: [{ name: { jam: 'Downtime', mnt: 'Menit' }[unit] || 'Reject', data: rows.map(r => r[field]) }],
            colors: [t.series[0]],
            plotOptions: { bar: { horizontal: true, barHeight: '62%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, style: { fontSize: '11px', fontWeight: 500, colors: [t.ink2] },
                formatter: (v, o) => `${fmt(v, dec)} ${unit} · ${fmt(rows[o.dataPointIndex].share, 0)}%` },
            xaxis: { categories: rows.map(r => r.name), labels: { formatter: v => short(v) }, axisBorder: { show: false }, axisTicks: { show: false },
                min: 0, max: niceCeil(Math.max(...rows.map(r => r[field])) * 1.35), tickAmount: 4 },
            yaxis: { labels: { maxWidth: 190, style: { fontSize: '12px', colors: t.ink2 } } },
            grid: { borderColor: t.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            legend: { show: false },
            tooltip: { theme: t.mode, y: { formatter: (v, o) => {
                const r = rows[o.dataPointIndex];
                return `${fmt(v, 1)} ${unit} · ${fmt(r.share, 1)}% · kumulatif ${fmt(r.cumulative, 1)}%` + (r.category ? ` · ${r.category}` : '');
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
            chart: { type: 'bar', height: 300, stacked: true },
            series: [
                { name: 'Baik', data: rows.map(r => r.ok) },
                { name: 'NC', data: rows.map(r => r.nc) },
                { name: 'Reject', data: rows.map(r => r.reject) },
            ],
            colors: [t.series[0], t.series[1], t.series[7]], // Baik biru, NC oranye, Reject merah
            plotOptions: { bar: { columnWidth: '52%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: rows.map(r => [r.area, (r.building || '').replace('Gd ', '')]), axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { style: { fontSize: '11px' } } },
            yaxis: { labels: { formatter: v => short(v) } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: (v, o) => {
                const r = rows[o.dataPointIndex];
                return `${fmt(v)} pcs` + (o.seriesIndex === 0 ? ` · cutting time ${this.pct(r.cutting_pct)}` : '');
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
            chart: { type: 'line', height: 340 },
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
                labels: { rotate: -45, rotateAlways: rows.length > 14, style: { fontSize: '11px' } } },
            yaxis: { min: 0, max: 110, tickAmount: 5, labels: { formatter: v => fmt(v, 0) + '%' } },
            annotations: avg === null || avg === undefined ? {} : { yaxis: [{ y: avg, borderColor: t.series[0], strokeDashArray: 5, borderWidth: 1.5,
                label: { text: 'Rata-rata ' + fmt(avg, 1) + '%', position: 'left', textAnchor: 'start', borderWidth: 0,
                    style: { background: t.series[0], color: '#fff', fontSize: '11px', padding: { left: 6, right: 6, top: 2, bottom: 2 } } } }] },
            legend: { show: false },
            tooltip: { theme: t.mode, shared: true, intersect: false, custom: ({ dataPointIndex: i }) => {
                const r = rows[i];
                return `<div class="px-3 py-2 text-[12px]"><div class="font-semibold mb-0.5">${r.code}</div>`
                    + `Cutting time ${fmt(r.cutting_pct, 1)}%<br>Downtime ${fmt(r.downtime_hours, 1)} jam · ${r.area || '-'}</div>`;
            } },
        }));
    },

    renderCustomers() {
        const t = theme(), rows = this.d.customers;
        if (!rows.length) return this.empty('ch-customers');
        this.draw('ch-customers', base(t, {
            chart: { type: 'bar', height: 380 },
            series: [{ name: 'Hasil baik', data: rows.map(r => r.qty) }],
            colors: [t.series[0]],
            plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, style: { fontSize: '11px', fontWeight: 500, colors: [t.ink2] }, formatter: v => short(v) },
            xaxis: { categories: rows.map(r => r.name), labels: { formatter: v => short(v) }, axisBorder: { show: false }, axisTicks: { show: false },
                min: 0, max: Math.max(...rows.map(r => r.qty)) * 1.3, tickAmount: 3 },
            yaxis: { labels: { maxWidth: 150, style: { fontSize: '11.5px', colors: t.ink2 } } },
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
            { key: 'pc', label: 'Total PC', value: fmt(k.total_pc), change: c.total_pc ?? null, sub: 'vs kemarin' },
            { key: 'eff', label: 'Efficiency', value: has(k.efficiency) ? fmt(k.efficiency, 0) + '%' : '-', change: c.efficiency ?? null, sub: 'Act ÷ Std' },
            { key: 'prod', label: 'Productivity', value: has(k.productivity) ? fmt(k.productivity, 0) + '%' : '-', change: c.productivity ?? null,
                sub: k.planned_qty ? `plan ${short(k.planned_qty)}` : 'belum ada planning' },
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
    empty(id, text = 'Belum ada data Form Produksi karu', h = 200) {
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

    /* 6. Bar Act/Std % per mesin (hijau >= target, merah < target) + lingkaran NG rate % di dasar batang */
    renderEfficiency() {
        const t = boardTheme(), rows = this.d.machines;
        if (!rows.length) return this.empty('ch-md-eff', undefined, 300);
        const eff = rows.map(r => r.efficiency ?? 0);
        const max = niceCeil(Math.max(100, ...eff) * 1.12);
        const bottom = max * 0.05; // posisi lingkaran NG rate di dasar batang
        this.draw('ch-md-eff', base(t, {
            chart: { type: 'line', height: 310, toolbar: { show: false } },
            series: [
                { name: 'Act/Std', type: 'column', data: rows.map(r => ({ x: r.code, y: r.efficiency ?? 0,
                    fillColor: (r.efficiency ?? 0) >= r.target_efficiency ? t.good : t.bad })) },
                { name: 'NG Rate %', type: 'line', data: rows.map(r => ({ x: r.code, y: bottom })) },
            ],
            colors: [t.good, t.pink],
            stroke: { width: [0, 0] },
            markers: { size: [0, 9], strokeWidth: 2, strokeColors: t.surface, colors: [t.pink], hover: { size: 10 } },
            plotOptions: { bar: { columnWidth: '60%', borderRadius: 3, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            dataLabels: { enabled: true, offsetY: -6, formatter: (v, o) => o.seriesIndex === 0 ? fmt(v, 0) + '%' : fmt(rows[o.dataPointIndex].ng_rate, 1) + '%',
                style: { fontSize: '9.5px', fontWeight: 600, colors: [t.ink, t.pink] }, background: { enabled: false } },
            xaxis: { type: 'category', axisBorder: { color: t.axis }, axisTicks: { show: false },
                labels: { rotate: -45, rotateAlways: rows.length > 8, style: { fontSize: '10px' } } },
            yaxis: { min: 0, max, tickAmount: 4, labels: { formatter: v => fmt(v, 0) + '%', style: { fontSize: '10px' } } },
            legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '11px', labels: { colors: t.ink2 }, markers: { size: 4 } },
            tooltip: { theme: t.mode, shared: true, intersect: false, custom: ({ dataPointIndex: i }) => {
                const r = rows[i];
                return `<div class="px-3 py-2 text-[12px]"><div class="font-semibold mb-0.5">${r.code}</div>`
                    + `Act/Std ${r.efficiency === null ? '-' : fmt(r.efficiency, 1) + '%'} (target ${fmt(r.target_efficiency, 0)}%)<br>`
                    + `NG rate ${fmt(r.ng_rate, 2)}% · ${fmt(r.ng)} dari ${fmt(r.pcs)} pcs</div>`;
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
const pad2 = (n) => String(n).padStart(2, '0');
const nowHM = () => { const d = new Date(); return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`; };
/* Shift karu: 1 = 07–18, 2 = 18–07. Lewat tengah malam masih shift 2 dengan tanggal hari sebelumnya. */
const guessShift = () => { const h = new Date().getHours(); return h >= 7 && h < 18 ? 1 : 2; };
const guessFormDate = (today) => {
    if (new Date().getHours() >= 7) return today;
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

        async addPhotos(row, files) {
            for (const f of Array.from(files || [])) {
                this.uploading++;
                row.photos.push({ id: null, url: URL.createObjectURL(f), uploading: true, error: '' });
                const tmp = row.photos[row.photos.length - 1]; // ambil versi reaktif (proxy) supaya tampilan ikut berubah
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
        removePhoto(row, i) { row.photos.splice(i, 1); },

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
            const f = JSON.parse(JSON.stringify(this.f, (k, v) => k === 'photos'
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
                    this.$nextTick(() => document.querySelector('[data-error="1"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
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
            area_id: p?.area_id || '',
            items: (p?.items || []).map(it => ({ ...this.newItem(), ...it, open: false })),
        });
        this.f.items = this.f.items.map(it => ({ ...this.newItem(), ...it }));
        if (!this.f.items.length) this.addItem();
        this.watchDraft();
    },
    /* Satu baris = satu PRO di satu mesin. PRO baru membawa mesin dari baris sebelumnya (ganti PRO di mesin yang sama). */
    newItem(prev = null) {
        return { machine_type: prev?.machine_type || '', machine_id: prev?.machine_id || '', order_no: '', dimension: '', working_minutes: '', cutting_minutes: '', std_output: '', act_output: '',
            ok_qty: '', second_grade_qty: '', nc_qty: '', note: '', problems: [], photos: [], open: true };
    },
    addItem() {
        const prev = this.f.items[this.f.items.length - 1];
        this.f.items.forEach(i => i.open = false);
        this.f.items.push(this.newItem(prev));
        this.$nextTick(() => document.getElementById('item-' + (this.f.items.length - 1))?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    removeItem(i) { if (confirm('Hapus PRO #' + (i + 1) + ' dari form?')) this.f.items.splice(i, 1); },
    machinesFor(it) {
        return this.cfg.machines.filter(m => (!it.machine_type || m.type === it.machine_type) && (!this.f.area_id || !m.area_id || String(m.area_id) === String(this.f.area_id)));
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
    fixProblems(it) {
        it.problems.forEach(p => {
            if (p.option_id && p.option_id !== 'other' && !this.problemOptions(it, p.kind).some(o => String(o.id) === String(p.option_id))) p.option_id = '';
        });
    },
    addProblem(it, kind) { it.problems.push({ kind, option_id: '', other_text: '', minutes: '' }); },
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
        this.submit({
            form_date: this.f.form_date, shift: Number(this.f.shift), area_id: this.clean(this.f.area_id),
            items: this.f.items.map(it => ({
                machine_type: it.machine_type || 'umum', machine_id: this.clean(it.machine_id), order_no: this.clean(it.order_no), dimension: this.clean(it.dimension),
                working_minutes: this.clean(it.working_minutes), cutting_minutes: this.clean(it.cutting_minutes),
                std_output: this.clean(it.std_output), act_output: this.clean(it.act_output), ok_qty: this.clean(it.ok_qty),
                second_grade_qty: this.clean(it.second_grade_qty), nc_qty: this.clean(it.nc_qty), note: this.clean(it.note),
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
            form_date: p?.form_date || guessFormDate(cfg.today),
            shift: p?.shift || guessShift(),
            area_id: p?.area_id || '',
            checks: (p?.checks || []).map(c => ({ ...this.newCheck(), ...c })),
        });
        this.f.checks = this.f.checks.map(c => ({ ...this.newCheck(), ...c }));
        if (!this.f.checks.length) this.addCheck();
        this.watchDraft();
    },
    newCheck(prev = null) {
        return { check_time: nowHM(), machine_id: prev?.machine_id || '', customer: prev?.customer || '', dimension: prev?.dimension || '',
            planned_start: prev?.planned_start || '', planned_qty: prev?.planned_qty ?? '',
            od_mm: '', d1: '', d2: '', d3: '', d4: '', thickness_mm: '', ib_height_mm: '', length_mm: '',
            jig_option_id: '', jig_other: '', visual_option_id: '', visual_other: '',
            compliance_ids: [], compliance_other_on: false, compliance_other: '',
            actual_cutting: '', problem: '', photos: [] };
    },
    /* Pengecekan baru otomatis membawa mesin, customer & dimensi dari pengecekan sebelumnya (bisa diganti). */
    addCheck() {
        const prev = this.f.checks[this.f.checks.length - 1];
        this.f.checks.push(this.newCheck(prev));
        this.$nextTick(() => document.getElementById('check-' + (this.f.checks.length - 1))?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    },
    toggleCompliance(c, id, on) {
        const rest = c.compliance_ids.filter(x => String(x) !== String(id));
        c.compliance_ids = on ? [...rest, id] : rest;
    },
    removeCheck(i) { if (confirm('Hapus baris pengecekan #' + (i + 1) + '?')) this.f.checks.splice(i, 1); },
    machinesFor() { return this.cfg.machines.filter(m => !this.f.area_id || !m.area_id || String(m.area_id) === String(this.f.area_id)); },
    optLabel(group, id) { return this.opts(group).find(o => String(o.id) === String(id))?.label || (id === 'other' ? 'Lainnya' : ''); },
    summary(c) {
        const parts = [c.check_time, this.machineCode(c.machine_id) || 'Mesin belum dipilih'];
        const v = this.optLabel('visual', c.visual_option_id);
        if (v) parts.push('Visual ' + v);
        if (c.photos.length) parts.push(`${c.photos.length} foto`);
        return parts.filter(Boolean).join(' · ');
    },
    save() {
        const n = (v) => this.clean(v);
        this.submit({
            form_date: this.f.form_date, shift: Number(this.f.shift), area_id: n(this.f.area_id),
            checks: this.f.checks.map(c => ({
                check_time: n(c.check_time), machine_id: n(c.machine_id), customer: n(c.customer), dimension: n(c.dimension),
                planned_start: n(c.planned_start), planned_qty: n(c.planned_qty),
                od_mm: n(c.od_mm), d1: n(c.d1), d2: n(c.d2), d3: n(c.d3), d4: n(c.d4), thickness_mm: n(c.thickness_mm),
                ib_height_mm: n(c.ib_height_mm), length_mm: n(c.length_mm),
                jig_option_id: this.optVal(c.jig_option_id), jig_other: c.jig_option_id === 'other' ? n(c.jig_other) : null,
                visual_option_id: this.optVal(c.visual_option_id), visual_other: c.visual_option_id === 'other' ? n(c.visual_other) : null,
                compliance_option_ids: c.compliance_ids.map(Number), compliance_other: c.compliance_other_on ? n(c.compliance_other) : null,
                actual_cutting: n(c.actual_cutting), problem: n(c.problem),
                attachments: c.photos.filter(x => x.id).map(x => x.id),
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
