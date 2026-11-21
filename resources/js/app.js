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
const SEQ_BLUE = ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95'];
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
        this.load();
        window.addEventListener('theme-changed', () => this.renderAll());
    },

    // ---------- util tampilan
    num: (v, d = 0) => fmt(v, d),
    pct: (v) => (v === null || v === undefined) ? '-' : fmt(v, 1) + '%',
    fmtDate,
    fmtRange(r) { return r ? `${fmtDate(r[0])} – ${fmtDate(r[1])}` : ''; },
    periodLabel() { return { day: 'hari', week: 'minggu', month: 'bulan' }[this.f.period]; },
    heatColors() { return SEQ_BLUE; },
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
        if (cfg.mode === 'karu') return this.renderKaru(); // halaman Monitoring Karu
        this.renderProduction();
        this.renderYield();
        this.renderDowntimeTrend();
        this.renderDowntimeCat();
        this.renderPareto('ch-downtime-pareto', this.d.downtime_pareto, 'hours', 'jam', 0);
        this.renderPareto('ch-defect-pareto', this.d.defect_pareto, 'qty', 'pcs', 0);
        this.renderNc();
        this.renderShift();
        this.renderArea();
        this.renderHeatmap();
        this.renderCustomers();
    },

    // ---------- kontrol karu
    karuKpis() {
        const k = this.d?.karu.kpi || {}, c = this.d?.karu.crosscheck || {};
        return [
            { label: 'Form Produksi', value: fmt(k.production_forms), sub: `${fmt(k.machines)} mesin dilaporkan` },
            { label: 'Form Evaluasi', value: fmt(k.evaluation_forms), sub: `${fmt(k.checks)} pengecekan` },
            { label: 'Karu aktif', value: fmt(k.karus), sub: 'mengisi form' },
            { label: 'Visual tidak OK', value: fmt(k.visual_bad), sub: 'NG / perlu tindak lanjut', alert: k.visual_bad > 0 },
            { label: 'Cocok dgn data admin', value: c.compared ? fmt((c.compared - c.flagged_count) / c.compared * 100, 0) + '%' : '-', sub: `${fmt(c.matched)} dari ${fmt(c.compared)} ada data admin` },
            { label: 'Perlu dicek', value: fmt(c.flagged_count), sub: 'laporan karu ≠ data admin', alert: c.flagged_count > 0 },
        ];
    },

    renderKaru() {
        const t = theme(), k = this.d.karu;
        if (k.problems.length) {
            this.renderPareto('ch-karu-problems', k.problems.map(r => ({ ...r, name: `${r.name} (${r.kind})` })), 'minutes', 'mnt', 0);
        } else {
            this.empty('ch-karu-problems', 'Belum ada Set Up / DT dari Form Produksi');
        }

        // donut visual: OK = hijau, NG = merah (status), perlu tindak lanjut = kuning, lainnya = abu
        const vis = k.visual;
        if (!vis.length) {
            this.empty('ch-karu-visual', 'Belum ada pengecekan visual');
        } else {
            const color = (n) => n === 'OK' ? t.series[2] : (n === 'NG' ? CRITICAL : (n === 'Lainnya' ? t.other : t.series[3]));
            const total = vis.reduce((a, r) => a + r.count, 0);
            this.draw('ch-karu-visual', base(t, {
                chart: { type: 'donut', height: 300 },
                series: vis.map(r => r.count),
                labels: vis.map(r => r.name),
                colors: vis.map(r => color(r.name)),
                stroke: { width: 2, colors: [t.surface] },
                legend: { position: 'bottom', fontSize: '12px', labels: { colors: t.ink2 }, markers: { size: 5, shape: 'circle' } },
                plotOptions: { pie: { donut: { size: '68%', labels: { show: true,
                    name: { fontSize: '12px', color: t.muted },
                    value: { fontSize: '22px', fontWeight: 600, color: t.ink, formatter: v => fmt(+v) },
                    total: { show: true, label: 'Pengecekan', color: t.muted, fontSize: '12px', formatter: () => fmt(total) } } } } },
                tooltip: { theme: t.mode, y: { formatter: v => `${fmt(v)} pengecekan (${fmt(v / total * 100, 1)}%)` } },
            }));
        }

        const hbar = (id, rows, emptyText) => {
            if (!rows.length) return this.empty(id, emptyText);
            this.draw(id, base(t, {
                chart: { type: 'bar', height: Math.max(220, rows.length * 38 + 60) },
                series: [{ name: 'Jumlah', data: rows.map(r => r.count) }],
                colors: [t.series[0]],
                plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, style: { fontSize: '11px', fontWeight: 500, colors: [t.ink2] }, formatter: v => fmt(v) },
                xaxis: { categories: rows.map(r => r.name), axisBorder: { show: false }, axisTicks: { show: false }, min: 0,
                    max: niceCeil(Math.max(...rows.map(r => r.count)) * 1.3), tickAmount: 4, labels: { formatter: v => fmt(v) } },
                yaxis: { labels: { maxWidth: 170, style: { fontSize: '12px', colors: t.ink2 } } },
                grid: { borderColor: t.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
                legend: { show: false },
                tooltip: { theme: t.mode, y: { formatter: v => fmt(v) + ' pengecekan' } },
            }));
        };
        hbar('ch-karu-compliance', k.compliance, 'Belum ada data kontrol kepatuhan');
        hbar('ch-karu-jig', k.jig, 'Belum ada data JIG');

        const a = k.by_karu;
        if (!a.length) return this.empty('ch-karu-activity', 'Belum ada form karu');
        this.draw('ch-karu-activity', base(t, {
            chart: { type: 'bar', height: Math.max(220, a.length * 34 + 70), stacked: true },
            series: [{ name: 'Mesin dilaporkan', data: a.map(r => r.prod) }, { name: 'Pengecekan', data: a.map(r => r.eval) }],
            colors: t.series.slice(0, 2),
            plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            stroke: { show: true, width: 2, colors: [t.surface] },
            xaxis: { categories: a.map(r => r.name), axisBorder: { show: false }, axisTicks: { show: false }, min: 0, tickAmount: 3, labels: { formatter: v => fmt(v) } },
            yaxis: { labels: { maxWidth: 150, style: { fontSize: '12px', colors: t.ink2 } } },
            grid: { borderColor: t.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            tooltip: { theme: t.mode, shared: true, intersect: false, y: { formatter: v => fmt(v) } },
        }));
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
            chart: { type: 'bar', height: 300, stacked: true },
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
        const order = ['menunggu', 'setup', 'setting', 'tooling', 'rutin', 'breakdown', 'preventive', 'lain'];
        return Object.fromEntries(order.map((k, i) => [k, t.series[i]]));
    },

    renderDowntimeCat() {
        const t = theme(), rows = this.d.downtime_category.filter(r => r.hours > 0);
        if (!rows.length) return this.empty('ch-downtime-cat');
        const colorOf = this.categoryColors(t);
        const total = rows.reduce((a, r) => a + r.hours, 0);
        this.draw('ch-downtime-cat', base(t, {
            chart: { type: 'donut', height: 320 },
            series: rows.map(r => r.hours),
            labels: rows.map(r => r.name),
            colors: rows.map(r => colorOf[r.key]),
            stroke: { width: 2, colors: [t.surface] },
            legend: { position: 'bottom', fontSize: '12px', labels: { colors: t.ink2 }, markers: { size: 5, shape: 'circle' } },
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

    renderHeatmap() {
        const t = theme(), h = this.d.heatmap;
        if (!h.rows.length) return this.empty('ch-heatmap');
        const series = [...h.rows].reverse().map(r => ({
            name: r.machine,
            data: h.periods.map((p, i) => ({ x: periodLabel(p, h.period), y: r.values[i] === null ? -1 : r.values[i] })),
        }));
        this.draw('ch-heatmap', base(t, {
            chart: { type: 'heatmap', height: Math.max(260, h.rows.length * 22 + 70), toolbar: { show: true, tools: { download: true } } },
            series,
            stroke: { width: 2, colors: [t.surface] },
            plotOptions: { heatmap: { radius: 3, enableShades: false, colorScale: { ranges: [
                { from: -1, to: -0.5, color: t.empty, name: 'Tidak ada data' },
                { from: 0, to: 60, color: SEQ_BLUE[0] },
                { from: 60, to: 75, color: SEQ_BLUE[1] },
                { from: 75, to: 85, color: SEQ_BLUE[2] },
                { from: 85, to: 92, color: SEQ_BLUE[3] },
                { from: 92, to: 96, color: SEQ_BLUE[4] },
                { from: 96, to: 101, color: SEQ_BLUE[5] },
            ] } } },
            legend: { show: false },
            xaxis: { axisBorder: { show: false }, axisTicks: { show: false }, labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } } },
            yaxis: { labels: { style: { fontSize: '11.5px', colors: t.ink2 } } },
            tooltip: { theme: t.mode, y: { formatter: v => v < 0 ? 'Tidak ada data' : fmt(v, 1) + '% cutting time' } },
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
const guessShift = () => { const h = new Date().getHours(); return h >= 7 && h < 15 ? 1 : (h >= 15 && h < 23 ? 2 : 3); };

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
                    const body = new FormData();
                    body.append('file', await compressImage(f));
                    const res = await fetch(cfg.uploadUrl, { method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.errors?.file?.[0] || data.message || 'Gagal mengunggah');
                    Object.assign(tmp, { id: data.id, uploading: false });
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
                if (!res.ok) { this.message = data.message || 'Gagal menyimpan. Coba lagi.'; return; }
                window.location = data.redirect;
            } catch (e) {
                this.message = 'Koneksi terputus. Data belum tersimpan, coba lagi.';
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
        this.f = {
            form_date: p?.form_date || cfg.today,
            shift: p?.shift || guessShift(),
            area_id: p?.area_id || '',
            items: (p?.items || []).map(it => ({ ...this.newItem(), ...it, open: false })),
        };
        if (!this.f.items.length) this.addItem();
    },
    newItem() {
        return { machine_type: '', machine_id: '', dimension: '', working_minutes: '', cutting_minutes: '', std_output: '', act_output: '',
            ok_qty: '', second_grade_qty: '', nc_qty: '', note: '', problems: [], photos: [], open: true };
    },
    addItem() {
        this.f.items.forEach(i => i.open = false);
        this.f.items.push(this.newItem());
        this.$nextTick(() => document.getElementById('item-' + (this.f.items.length - 1))?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    removeItem(i) { if (confirm('Hapus mesin #' + (i + 1) + ' dari form?')) this.f.items.splice(i, 1); },
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
        return kind === 'setup' ? this.opts('setup') : this.opts('dt').filter(o => !o.type || o.type === (it.machine_type || 'umum'));
    },
    fixProblems(it) {
        it.problems.forEach(p => {
            if (p.option_id && p.option_id !== 'other' && !this.problemOptions(it, p.kind).some(o => String(o.id) === String(p.option_id))) p.option_id = '';
        });
    },
    addProblem(it, kind) { it.problems.push({ kind, option_id: '', other_text: '', minutes: '' }); },
    totalMinutes(it, kind) { return it.problems.filter(p => p.kind === kind).reduce((a, p) => a + (+p.minutes || 0), 0); },
    summary(it) {
        const parts = [this.machineCode(it.machine_id) || 'Mesin belum dipilih'];
        if (it.ok_qty !== '' && it.ok_qty !== null) parts.push(`OK ${fmt(+it.ok_qty)}`);
        const dt = this.totalMinutes(it, 'dt'), su = this.totalMinutes(it, 'setup');
        if (dt) parts.push(`DT ${dt} mnt`);
        if (su) parts.push(`Set up ${su} mnt`);
        if (it.photos.length) parts.push(`${it.photos.length} foto`);
        return parts.join(' · ');
    },
    save() {
        this.submit({
            form_date: this.f.form_date, shift: Number(this.f.shift), area_id: this.clean(this.f.area_id),
            items: this.f.items.map(it => ({
                machine_type: it.machine_type || 'umum', machine_id: this.clean(it.machine_id), dimension: this.clean(it.dimension),
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
        this.f = {
            form_date: p?.form_date || cfg.today,
            shift: p?.shift || guessShift(),
            area_id: p?.area_id || '',
            checks: (p?.checks || []).map(c => ({ ...this.newCheck(), ...c })),
        };
        if (!this.f.checks.length) this.addCheck();
    },
    newCheck(prev = null) {
        return { check_time: nowHM(), machine_id: prev?.machine_id || '', customer: prev?.customer || '', dimension: prev?.dimension || '',
            od_mm: '', d1: '', d2: '', d3: '', d4: '', thickness_mm: '', ib_height_mm: '', length_mm: '',
            jig_option_id: '', jig_other: '', visual_option_id: '', visual_other: '', compliance_option_id: '', compliance_other: '',
            actual_cutting: '', problem: '', photos: [] };
    },
    /* Pengecekan baru otomatis membawa mesin, customer & dimensi dari pengecekan sebelumnya (bisa diganti). */
    addCheck() {
        const prev = this.f.checks[this.f.checks.length - 1];
        this.f.checks.push(this.newCheck(prev));
        this.$nextTick(() => document.getElementById('check-' + (this.f.checks.length - 1))?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
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
                od_mm: n(c.od_mm), d1: n(c.d1), d2: n(c.d2), d3: n(c.d3), d4: n(c.d4), thickness_mm: n(c.thickness_mm),
                ib_height_mm: n(c.ib_height_mm), length_mm: n(c.length_mm),
                jig_option_id: this.optVal(c.jig_option_id), jig_other: c.jig_option_id === 'other' ? n(c.jig_other) : null,
                visual_option_id: this.optVal(c.visual_option_id), visual_other: c.visual_option_id === 'other' ? n(c.visual_other) : null,
                compliance_option_id: this.optVal(c.compliance_option_id), compliance_other: c.compliance_option_id === 'other' ? n(c.compliance_other) : null,
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
