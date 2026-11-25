<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Semua angka dashboard diambil dari tabel ringkasan harian (bukan data mentah),
 * difilter periode, lalu di-cache sampai ada import baru.
 */
class DashboardService
{
    public function filters(array $in): array
    {
        $maxDate = DB::table('daily_machine_summaries')->max('production_date') ?? now()->toDateString();
        $to = ! empty($in['to']) ? Carbon::parse($in['to']) : Carbon::parse($maxDate);
        $from = ! empty($in['from']) ? Carbon::parse($in['from']) : $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $days = $from->diffInDays($to) + 1;
        $period = in_array($in['period'] ?? null, ['day', 'week', 'month'], true)
            ? $in['period']
            : ($days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month'));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => $period,
            'shift' => in_array((int) ($in['shift'] ?? 0), [1, 2, 3], true) ? (int) $in['shift'] : null,
            'area_id' => ! empty($in['area_id']) ? (int) $in['area_id'] : null,
            'building' => ! empty($in['building']) ? (string) $in['building'] : null,
            'machine_id' => ! empty($in['machine_id']) ? (int) $in['machine_id'] : null,
        ];
    }

    public function data(array $f): array
    {
        $key = 'dash2:v'.Cache::get('dashboard_version', 1).':'.md5(json_encode($f));

        return Cache::remember($key, now()->addMinutes(30), fn () => $this->build($f));
    }

    private function build(array $f): array
    {
        $prev = $this->previousRange($f);

        return [
            'filters' => $f,
            'kpi' => $this->kpi($f),
            'kpi_prev' => $this->kpi($prev),
            'prev_range' => [$prev['from'], $prev['to']],
            'trend' => $this->trend($f),
            'downtime_trend' => $this->downtimeTrend($f),
            'downtime_pareto' => $this->downtimePareto($f),
            'downtime_category' => $this->downtimeCategory($f),
            'defect_pareto' => $this->defectPareto($f),
            'nc_causes' => $this->ncCauses($f),
            'shifts' => $this->byShift($f),
            'areas' => $this->byArea($f),
            'machines' => $this->byMachine($f),
            // 'sap' => $this->sapCompare($f), // Yield SAP vs lapangan: di-hold, tidak ditampilkan
            'customers' => $this->topCustomers($f),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function base(string $table, array $f, string $alias = 's'): Builder
    {
        return DB::table("$table as $alias")
            ->whereBetween("$alias.production_date", [$f['from'], $f['to']])
            ->when($f['shift'], fn ($q) => $q->where("$alias.shift", $f['shift']))
            ->when($f['area_id'], fn ($q) => $q->where("$alias.area_id", $f['area_id']))
            ->when($f['building'], fn ($q) => $q->where("$alias.building", $f['building']))
            ->when($f['machine_id'], fn ($q) => $q->where("$alias.machine_id", $f['machine_id']))
            ->when($table === 'production_reports', fn ($q) => $q->whereNull("$alias.deleted_at"));
    }

    private function periodExpr(string $period, string $col = 's.production_date'): string
    {
        return match ($period) {
            'week' => "DATE_SUB($col, INTERVAL WEEKDAY($col) DAY)",
            'month' => "DATE_FORMAT($col, '%Y-%m-01')",
            default => $col,
        };
    }

    private function previousRange(array $f): array
    {
        $from = Carbon::parse($f['from']);
        $to = Carbon::parse($f['to']);
        $days = $from->diffInDays($to) + 1;

        return array_merge($f, ['from' => $from->copy()->subDays($days)->toDateString(), 'to' => $from->copy()->subDay()->toDateString()]);
    }

    private static function pct(float $a, float $b): ?float
    {
        return $b > 0 ? round($a / $b * 100, 2) : null;
    }

    private const SUMS = 'SUM(s.ok_qty) ok, SUM(s.nc_qty) nc, SUM(s.reject_qty) rej, SUM(s.total_cutting_qty) total,
        SUM(s.working_minutes) work, SUM(s.downtime_minutes) dt, SUM(s.setup_minutes) setup, SUM(s.cutting_minutes) cut,
        SUM(s.ok_kg) ok_kg, SUM(s.total_kg) total_kg, SUM(s.gi_mm) gi, SUM(s.gr_ok_mm) gro, SUM(s.gr_nc_mm) grn,
        SUM(s.capacity_qty) cap, SUM(s.capacity_cutting_qty) capcut, SUM(s.report_count) reports';

    private function metrics(object $r): array
    {
        return [
            'ok' => (int) $r->ok,
            'nc' => (int) $r->nc,
            'reject' => (int) $r->rej,
            'total' => (int) $r->total,
            'ok_kg' => round((float) $r->ok_kg, 1),
            'working_hours' => round($r->work / 60, 1),
            'downtime_hours' => round($r->dt / 60, 1),
            'reports' => (int) ($r->reports ?? 0),
            'cutting_pct' => self::pct((float) $r->cut, (float) $r->work),
            'downtime_pct' => self::pct((float) $r->dt, (float) $r->work),
            'nc_pct' => self::pct((float) $r->nc, (float) $r->total),
            'reject_pct' => self::pct((float) $r->rej, (float) $r->total),
            'yield_ok' => self::pct((float) $r->gro, (float) $r->gi),
            'yield_sap' => self::pct((float) $r->grn, (float) $r->gi),
            'achievement' => self::pct((float) $r->capcut, (float) $r->cap),
        ];
    }

    // ---------------------------------------------------------------- blocks

    private function kpi(array $f): array
    {
        $r = $this->base('daily_machine_summaries', $f)->selectRaw(self::SUMS.', COUNT(DISTINCT s.machine_id) machines, COUNT(DISTINCT s.production_date) days')->first();

        return $this->metrics($r) + ['machines' => (int) $r->machines, 'days' => (int) $r->days];
    }

    /**
     * Tren hasil produksi / yield untuk filter area & mesin milik satu grafik (di luar filter utama).
     *
     * @return array{trend: list<array>}
     */
    public function trendData(array $f): array
    {
        $key = 'dash-trend:v'.Cache::get('dashboard_version', 1).':'.md5(json_encode($f));

        return Cache::remember($key, now()->addMinutes(30), fn () => ['trend' => $this->trend($f)]);
    }

    private function trend(array $f): array
    {
        $p = $this->periodExpr($f['period']);
        $rows = $this->base('daily_machine_summaries', $f)->selectRaw("$p AS period, ".self::SUMS)
            ->groupByRaw($p)->orderByRaw($p)->get();

        return $rows->map(fn ($r) => ['period' => substr((string) $r->period, 0, 10)] + $this->metrics($r))->values()->all();
    }

    private function downtimeTrend(array $f): array
    {
        $p = $this->periodExpr($f['period']);
        $rows = $this->base('daily_downtime_summaries', $f)
            ->join('downtime_categories as c', 'c.id', '=', 's.downtime_category_id')
            ->selectRaw("$p AS period, c.key, c.name, c.sort, SUM(s.minutes) m")
            ->groupByRaw("$p, c.key, c.name, c.sort")->orderByRaw($p)->get();

        $cutting = $this->base('daily_machine_summaries', $f)->selectRaw("$p AS period, SUM(s.cutting_minutes) m")
            ->groupByRaw($p)->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->period, 0, 10) => (float) $r->m]);

        $periods = $rows->pluck('period')->map(fn ($p) => substr((string) $p, 0, 10))
            ->merge($cutting->filter()->keys())->unique()->sort()->values();
        $cats = $rows->sortBy('sort')->unique('key')->values();

        return [
            'periods' => $periods->all(),
            'series' => $cats->map(fn ($c) => [
                'key' => $c->key,
                'name' => $c->name,
                'data' => $periods->map(fn ($p) => round((float) $rows->first(fn ($r) => substr((string) $r->period, 0, 10) === $p && $r->key === $c->key)?->m / 60, 1))->all(),
            ])->all(),
            'cutting' => $periods->map(fn ($p) => round(($cutting[$p] ?? 0) / 60, 1))->all(),
        ];
    }

    private function downtimePareto(array $f): array
    {
        $rows = $this->base('daily_downtime_summaries', $f)
            ->join('downtime_reasons as r', 'r.id', '=', 's.downtime_reason_id')
            ->join('downtime_categories as c', 'c.id', '=', 's.downtime_category_id')
            ->selectRaw('r.name, c.name cat, c.key cat_key, SUM(s.minutes) m, SUM(s.occurrences) n')
            ->groupBy('r.name', 'c.name', 'c.key')->orderByDesc('m')->get();
        $total = max(1, $rows->sum('m'));
        $cum = 0;

        return $rows->take(10)->map(function ($r) use ($total, &$cum) {
            $cum += $r->m;

            return ['name' => $r->name, 'category' => $r->cat, 'category_key' => $r->cat_key, 'hours' => round($r->m / 60, 1),
                'occurrences' => (int) $r->n, 'share' => round($r->m / $total * 100, 1), 'cumulative' => round($cum / $total * 100, 1)];
        })->values()->all();
    }

    /**
     * Komposisi jam kerja: Set Up & Downtime (kelompok kolom sheet DT) + Cutting time (jam kerja − downtime).
     *
     * @return list<array{key: string, name: string, hours: float}>
     */
    private function downtimeCategory(array $f): array
    {
        $rows = $this->base('daily_downtime_summaries', $f)
            ->join('downtime_categories as c', 'c.id', '=', 's.downtime_category_id')
            ->selectRaw('c.key, c.name, c.sort, SUM(s.minutes) m')
            ->groupBy('c.key', 'c.name', 'c.sort')->orderBy('c.sort')->get()
            ->map(fn ($r) => ['key' => $r->key, 'name' => $r->name, 'hours' => round($r->m / 60, 1)])->values();

        $cutting = (float) $this->base('daily_machine_summaries', $f)->sum('s.cutting_minutes');

        return $rows->push(['key' => 'cutting', 'name' => 'Cutting time', 'hours' => round($cutting / 60, 1)])->all();
    }

    /**
     * Rincian satu kelompok (Set Up / Downtime) saat grafik diklik: alasan (kolom sheet DT), mesin terbanyak
     * dan catatan keterangan dari Excel. $period = awal batang yang diklik (hari / minggu / bulan), null = seluruh filter.
     * $reason = satu kolom DT (klik pareto downtime): rincian dibatasi ke kolom itu dan semua mesinnya ditampilkan.
     *
     * @return array{category: string, reason: ?string, from: string, to: string, total_hours: float, reasons: list<array>, machines: list<array>, notes: list<array>}
     */
    public function downtimeDetail(array $f, string $category, ?string $period = null, ?string $reason = null): array
    {
        if ($period) {
            $start = Carbon::parse($period);
            $end = match ($f['period']) {
                'week' => $start->copy()->addDays(6),
                'month' => $start->copy()->endOfMonth(),
                default => $start->copy(),
            };
            $f['from'] = max($f['from'], $start->toDateString());
            $f['to'] = min($f['to'], $end->toDateString());
        }

        $sum = fn () => $this->base('daily_downtime_summaries', $f)
            ->join('downtime_categories as c', 'c.id', '=', 's.downtime_category_id')
            ->join('downtime_reasons as r', 'r.id', '=', 's.downtime_reason_id')
            ->where('c.key', $category)
            ->when($reason, fn ($q) => $q->where('r.name', $reason));

        $reasons = $sum()
            ->selectRaw('r.name, SUM(s.minutes) m, COUNT(*) n, COUNT(DISTINCT s.machine_id) machines')
            ->groupBy('r.name')->havingRaw('SUM(s.minutes) > 0')->orderByDesc('m')->get();
        $total = max(1, (int) $reasons->sum('m'));

        $machines = $sum()->join('machines as mc', 'mc.id', '=', 's.machine_id')
            ->selectRaw('mc.code, SUM(s.minutes) m, COUNT(*) n')->groupBy('mc.code')->havingRaw('SUM(s.minutes) > 0')
            ->orderByDesc('m')->limit($reason ? 50 : 5)->get();

        $notes = $this->base('production_reports', $f, 's')
            ->join('machines as mc', 'mc.id', '=', 's.machine_id')
            ->join('production_downtimes as d', 'd.production_report_id', '=', 's.id')
            ->join('downtime_reasons as r', 'r.id', '=', 'd.downtime_reason_id')
            ->join('downtime_categories as c', 'c.id', '=', 'r.downtime_category_id')
            ->where('c.key', $category)->where('d.minutes', '>', 0)
            ->when($reason, fn ($q) => $q->where('r.name', $reason))
            ->whereNotNull('s.downtime_note')->where('s.downtime_note', '<>', '')
            ->selectRaw('s.id, s.production_date, s.shift, mc.code, s.order_no, s.downtime_note, SUM(d.minutes) m')
            ->groupBy('s.id', 's.production_date', 's.shift', 'mc.code', 's.order_no', 's.downtime_note')
            ->orderByDesc('m')->limit(30)->get();

        return [
            'category' => (string) config("production.downtime_categories.$category"),
            'reason' => $reason,
            'from' => $f['from'],
            'to' => $f['to'],
            'total_hours' => round($total / 60, 1),
            'reasons' => $reasons->map(fn ($r) => [
                'name' => $r->name, 'hours' => round($r->m / 60, 1), 'minutes' => (int) $r->m,
                'occurrences' => (int) $r->n, 'machines' => (int) $r->machines, 'share' => round($r->m / $total * 100, 1),
            ])->values()->all(),
            'machines' => $machines->map(fn ($r) => [
                'code' => $r->code, 'hours' => round($r->m / 60, 1), 'occurrences' => (int) $r->n, 'share' => round($r->m / $total * 100, 1),
            ])->values()->all(),
            'notes' => $notes->map(fn ($r) => [
                'date' => substr((string) $r->production_date, 0, 10), 'shift' => (int) $r->shift, 'machine' => $r->code,
                'order_no' => $r->order_no, 'note' => $r->downtime_note, 'minutes' => (int) $r->m,
            ])->values()->all(),
        ];
    }

    /**
     * 10 jenis reject teratas beserta mesin penyumbangnya (mis. Coaxial Over hanya di mesin swaging).
     * Dijumlah per id jenis & mesin tanpa join (cepat untuk rentang panjang), nama dipasang setelahnya.
     *
     * @return list<array{name: string, qty: int, share: float, cumulative: float, machines: list<array{code: string, qty: int}>}>
     */
    private function defectPareto(array $f): array
    {
        $rows = $this->base('daily_defect_summaries', $f)
            ->selectRaw('s.defect_type_id, s.machine_id, SUM(s.qty) q')->groupBy('s.defect_type_id', 's.machine_id')->get();
        $typeName = DB::table('defect_types')->whereIn('id', $rows->pluck('defect_type_id')->unique())->pluck('name', 'id');
        $machineCode = DB::table('machines')->whereIn('id', $rows->pluck('machine_id')->unique())->pluck('code', 'id');

        $byType = $rows->groupBy(fn ($r) => $typeName[$r->defect_type_id] ?? '-')
            ->map(fn ($g, $name) => [
                'name' => $name,
                'qty' => (int) $g->sum('q'),
                'machines' => $g->groupBy(fn ($r) => $machineCode[$r->machine_id] ?? '-')
                    ->map(fn ($m, $code) => ['code' => $code, 'qty' => (int) $m->sum('q')])
                    ->filter(fn ($m) => $m['qty'] > 0)->sortByDesc('qty')->values()->all(),
            ])
            ->sortByDesc('qty')->values();
        $total = max(1, $byType->sum('qty'));
        $cum = 0;

        return $byType->take(10)->map(function ($r) use ($total, &$cum) {
            $cum += $r['qty'];

            return ['name' => $r['name'], 'qty' => $r['qty'], 'share' => round($r['qty'] / $total * 100, 1),
                'cumulative' => round($cum / $total * 100, 1), 'machines' => $r['machines']];
        })->values()->all();
    }

    private function ncCauses(array $f): array
    {
        $rows = $this->base('production_reports', $f, 's')->where('s.nc_qty', '>', 0)
            ->selectRaw('s.nc_cause name, SUM(s.nc_qty) q')->groupBy('s.nc_cause')->orderByDesc('q')->get();
        $top = $rows->take(5)->map(fn ($r) => ['name' => ucwords(strtolower($r->name ?? 'Tanpa keterangan')), 'qty' => (int) $r->q])->values();
        $other = $rows->slice(5)->sum('q');
        if ($other > 0) {
            $top->push(['name' => 'Lainnya', 'qty' => (int) $other]);
        }

        return $top->all();
    }

    private function byShift(array $f): array
    {
        return $this->base('daily_machine_summaries', $f)->selectRaw('s.shift, '.self::SUMS)
            ->groupBy('s.shift')->orderBy('s.shift')->get()
            ->map(fn ($r) => ['shift' => (int) $r->shift] + $this->metrics($r))->values()->all();
    }

    /**
     * Satu batang per area (tidak dipecah per gudang) beserta daftar mesin yang berproduksi di area itu.
     *
     * @return list<array<string, mixed>>
     */
    private function byArea(array $f): array
    {
        $machines = $this->base('daily_machine_summaries', $f)->join('machines as m', 'm.id', '=', 's.machine_id')
            ->leftJoin('areas as a', 'a.id', '=', 's.area_id')
            ->selectRaw("COALESCE(a.name, 'Tanpa area') area, m.code, SUM(s.ok_qty) ok")
            ->groupBy('a.name', 'm.code')->orderBy('m.code')->get()->groupBy('area');

        return $this->base('daily_machine_summaries', $f)->leftJoin('areas as a', 'a.id', '=', 's.area_id')
            ->selectRaw("COALESCE(a.name, 'Tanpa area') area, ".self::SUMS)
            ->groupBy('a.name')->orderBy('a.name')->get()
            ->map(fn ($r) => ['area' => $r->area] + $this->metrics($r) + [
                'machines' => ($machines[$r->area] ?? collect())->map(fn ($m) => ['code' => $m->code, 'ok' => (int) $m->ok])->values()->all(),
            ])->values()->all();
    }

    private function byMachine(array $f): array
    {
        return $this->base('daily_machine_summaries', $f)->join('machines as m', 'm.id', '=', 's.machine_id')
            ->leftJoin('areas as a', 'a.id', '=', 's.area_id')
            ->selectRaw('m.id, m.code, a.name area, m.building, '.self::SUMS)
            ->groupBy('m.id', 'm.code', 'a.name', 'm.building')->orderBy('m.code')->get()
            ->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'area' => $r->area, 'building' => $r->building] + $this->metrics($r))
            ->values()->all();
    }

    private function sapCompare(array $f): array
    {
        $threshold = config('production.anomaly.yield_diff');
        $q = DB::table('sap_order_results as s')
            ->join('production_orders as o', 'o.order_no', '=', 's.order_no')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('materials as mt', 'mt.id', '=', 'o.material_id')
            ->whereBetween('o.last_date', [$f['from'], $f['to']])
            ->whereNotNull('s.gr_percent')->where('o.gi_mm', '>', 0);
        if ($f['machine_id'] || $f['area_id'] || $f['building'] || $f['shift']) {
            $q->whereExists(fn ($e) => $e->from('production_reports as r')->whereColumn('r.production_order_id', 'o.id')->whereNull('r.deleted_at')
                ->when($f['machine_id'], fn ($x) => $x->where('r.machine_id', $f['machine_id']))
                ->when($f['area_id'], fn ($x) => $x->where('r.area_id', $f['area_id']))
                ->when($f['building'], fn ($x) => $x->where('r.building', $f['building']))
                ->when($f['shift'], fn ($x) => $x->where('r.shift', $f['shift'])));
        }
        $rows = $q->selectRaw('s.order_no, c.name customer, mt.code material, s.gi_kg, s.gr_percent sap,
                o.gr_nc_mm / o.gi_mm * 100 field_nc, o.gr_ok_mm / o.gi_mm * 100 field_ok, o.report_count, o.last_date')->get();

        $mapped = $rows->map(fn ($r) => [
            'order' => $r->order_no, 'customer' => $r->customer, 'material' => $r->material,
            'gi_kg' => (float) $r->gi_kg, 'sap' => round((float) $r->sap, 2),
            'field_nc' => round((float) $r->field_nc, 2), 'field_ok' => round((float) $r->field_ok, 2),
            'diff' => round($r->sap - $r->field_nc, 2), 'reports' => (int) $r->report_count,
        ]);
        $flagged = $mapped->filter(fn ($r) => abs($r['diff']) > $threshold || $r['sap'] > 100)
            ->sortByDesc(fn ($r) => abs($r['diff']))->values();

        return [
            'compared' => $mapped->count(),
            'flagged_count' => $flagged->count(),
            'threshold' => $threshold,
            'avg_sap' => $mapped->count() ? round($mapped->avg('sap'), 2) : null,
            'avg_field_ok' => $mapped->count() ? round($mapped->avg('field_ok'), 2) : null,
            'rows' => $flagged->take(15)->all(),
        ];
    }

    private function topCustomers(array $f): array
    {
        return $this->base('production_reports', $f, 's')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->selectRaw('c.id, c.name, SUM(s.ok_qty) q, SUM(s.ok_kg) kg')->groupBy('c.id', 'c.name')->orderByDesc('q')->limit(8)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'qty' => (int) $r->q, 'kg' => round((float) $r->kg)])->values()->all();
    }
}
