<x-layouts.app title="Monitoring Karu">
<div x-data="dashboard(@js([
        'mode' => 'karu',
        'filters' => $filters,
        'endpoint' => route('forms.monitoring.data'),
        'canOpenForms' => true,
        'machines' => $machines,
        'minDate' => $range->min ?? null,
        'maxDate' => $range->max ?? null,
    ]))" x-init="init()" class="space-y-4">

    <div>
        <h1 class="text-[18px] font-semibold tracking-tight">Monitoring karu</h1>
        <p class="text-[13px] mt-0.5" style="color:var(--muted)">Isian Form Produksi & Form Evaluasi dari Kepala Regu, dicocokkan dengan data import admin pada tanggal, shift dan mesin yang sama.</p>
    </div>

    @include('dashboard._filters', ['karuMode' => true])

    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
        <template x-for="k in karuKpis()" :key="k.label">
            <div class="card card-pad !py-3.5">
                <div class="text-[12.5px]" style="color:var(--muted)" x-text="k.label"></div>
                <div class="kpi-value mt-1" :style="k.alert && 'color:var(--bad)'" x-text="k.value"></div>
                <div class="text-[12px] mt-0.5" style="color:var(--muted)" x-text="k.sub"></div>
            </div>
        </template>
    </div>

    <div class="card">
        <div class="card-pad !pb-2 flex flex-wrap items-start gap-3">
            <div class="flex-1 min-w-[240px]">
                <div class="card-title">Laporan karu vs data admin</div>
                <div class="card-sub">Mesin + tanggal + shift yang sama. Ditandai jika downtime (selisih &gt; 15 mnt & 20%), cutting time (&gt; 15 mnt & 10%), atau hasil OK (&gt; 10%) berbeda.</div>
            </div>
            <span class="chip" :class="d?.karu.crosscheck.flagged_count ? 'chip-crit' : 'chip-ok'"
                  x-text="d ? `${d.karu.crosscheck.flagged_count} dari ${d.karu.crosscheck.compared} perlu dicek` : ''"></span>
        </div>
        <div class="overflow-x-auto px-2 pb-2 max-h-[420px]">
            <table class="tbl">
                <thead><tr><th>Tanggal</th><th>Mesin</th><th>Karu</th><th class="num">DT karu</th><th class="num">DT admin</th><th class="num">Cutting karu</th><th class="num">Cutting admin</th><th class="num">OK karu</th><th class="num">OK admin</th><th>Temuan</th></tr></thead>
                <tbody>
                <template x-for="r in (d?.karu.crosscheck.rows || [])" :key="r.form_id + r.machine + r.date">
                    <tr :class="cfg.canOpenForms && 'cursor-pointer'" @click="cfg.canOpenForms && (window.location = r.url)">
                        <td x-text="fmtDate(r.date) + ' · S' + r.shift"></td>
                        <td class="font-medium" x-text="r.machine"></td>
                        <td x-text="r.karu"></td>
                        <td class="num" x-text="num(r.karu_dt)"></td>
                        <td class="num" x-text="num(r.admin_dt)"></td>
                        <td class="num" x-text="num(r.karu_cut)"></td>
                        <td class="num" x-text="num(r.admin_cut)"></td>
                        <td class="num" x-text="num(r.karu_ok)"></td>
                        <td class="num" x-text="num(r.admin_ok)"></td>
                        <td><div class="flex flex-wrap gap-1"><template x-for="x in r.issues"><span class="chip chip-crit" x-text="x"></span></template></div></td>
                    </tr>
                </template>
                <tr x-show="d && !d.karu.crosscheck.rows.length"><td colspan="10" class="text-center !py-8" style="color:var(--muted)"
                    x-text="d?.karu.crosscheck.compared ? 'Semua laporan karu cocok dengan data admin.' : 'Belum ada Form Produksi pada periode ini.'"></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card card-pad xl:col-span-2">
            <div class="card-title">Problem terbanyak menurut karu</div>
            <div class="card-sub">Total menit Set Up & DT dari Form Produksi</div>
            <div id="ch-karu-problems" class="mt-2 min-h-[300px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Hasil visual</div>
            <div class="card-sub">Dari pengecekan Form Evaluasi</div>
            <div id="ch-karu-visual" class="mt-2 min-h-[300px]"></div>
        </div>
    </div>
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <div class="card card-pad">
            <div class="card-title">Kontrol kepatuhan</div>
            <div class="card-sub">Jumlah temuan per poin</div>
            <div id="ch-karu-compliance" class="mt-2 min-h-[260px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">JIG</div>
            <div class="card-sub">Jumlah pengecekan per JIG</div>
            <div id="ch-karu-jig" class="mt-2 min-h-[260px]"></div>
        </div>
        <div class="card card-pad">
            <div class="card-title">Aktivitas per karu</div>
            <div class="card-sub">Mesin dilaporkan & pengecekan dilakukan</div>
            <div id="ch-karu-activity" class="mt-2 min-h-[260px]"></div>
        </div>
    </div>
</div>
</x-layouts.app>
