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
        $key = 'dash:v'.Cache::get('dashboard_version', 1).':'.md5(json_encode($f));

        return Cache::remember($key, now()->addMinutes(30), fn () => $this->build($f));
    }

    /** Data halaman Monitoring Karu (Form Produksi & Form Evaluasi vs data import). */
    public function karuData(array $f): array
    {
        $key = 'karu:v'.Cache::get('dashboard_version', 1).':'.md5(json_encode($f));

        return Cache::remember($key, now()->addMinutes(30), fn () => [
            'filters' => $f,
            'karu' => $this->karu($f),
            'generated_at' => now()->toDateTimeString(),
        ]);
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
            'heatmap' => $this->heatmap($f),
            'sap' => $this->sapCompare($f),
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

        $periods = $rows->pluck('period')->map(fn ($p) => substr((string) $p, 0, 10))->unique()->values();
        $cats = $rows->sortBy('sort')->unique('key')->values();

        return [
            'periods' => $periods->all(),
            'series' => $cats->map(fn ($c) => [
                'key' => $c->key,
                'name' => $c->name,
                'data' => $periods->map(fn ($p) => round((float) $rows->first(fn ($r) => substr((string) $r->period, 0, 10) === $p && $r->key === $c->key)?->m / 60, 1))->all(),
            ])->all(),
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

    private function downtimeCategory(array $f): array
    {
        return $this->base('daily_downtime_summaries', $f)
            ->join('downtime_categories as c', 'c.id', '=', 's.downtime_category_id')
            ->selectRaw('c.key, c.name, c.sort, SUM(s.minutes) m')
            ->groupBy('c.key', 'c.name', 'c.sort')->orderBy('c.sort')->get()
            ->map(fn ($r) => ['key' => $r->key, 'name' => $r->name, 'hours' => round($r->m / 60, 1)])->values()->all();
    }

    private function defectPareto(array $f): array
    {
        $rows = $this->base('daily_defect_summaries', $f)
            ->join('defect_types as d', 'd.id', '=', 's.defect_type_id')
            ->selectRaw('d.name, SUM(s.qty) q')->groupBy('d.name')->orderByDesc('q')->get();
        $total = max(1, $rows->sum('q'));
        $cum = 0;

        return $rows->take(10)->map(function ($r) use ($total, &$cum) {
            $cum += $r->q;

            return ['name' => $r->name, 'qty' => (int) $r->q, 'share' => round($r->q / $total * 100, 1), 'cumulative' => round($cum / $total * 100, 1)];
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

    private function byArea(array $f): array
    {
        return $this->base('daily_machine_summaries', $f)->leftJoin('areas as a', 'a.id', '=', 's.area_id')
            ->selectRaw("COALESCE(a.name, 'Tanpa area') area, s.building, ".self::SUMS)
            ->groupBy('a.name', 's.building')->orderBy('a.name')->get()
            ->map(fn ($r) => ['area' => $r->area, 'building' => $r->building] + $this->metrics($r))->values()->all();
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

    private function heatmap(array $f): array
    {
        $period = $f['period'];
        $days = Carbon::parse($f['from'])->diffInDays(Carbon::parse($f['to'])) + 1;
        if ($period === 'day' && $days > 35) {
            $period = 'week';
        }
        $p = $this->periodExpr($period);
        $rows = $this->base('daily_machine_summaries', $f)->join('machines as m', 'm.id', '=', 's.machine_id')
            ->selectRaw("m.code, $p period, SUM(s.cutting_minutes) cut, SUM(s.working_minutes) work")
            ->groupByRaw("m.code, $p")->get();
        $periods = $rows->pluck('period')->map(fn ($x) => substr((string) $x, 0, 10))->unique()->sort()->values();
        $byMachine = $rows->groupBy('code')->sortKeys();

        return [
            'period' => $period,
            'periods' => $periods->all(),
            'rows' => $byMachine->map(function ($items, $code) use ($periods) {
                $map = $items->keyBy(fn ($i) => substr((string) $i->period, 0, 10));

                return ['machine' => $code, 'values' => $periods->map(fn ($p) => isset($map[$p]) ? self::pct((float) $map[$p]->cut, (float) $map[$p]->work) : null)->all()];
            })->values()->all(),
        ];
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

    // ---------------------------------------------------------------- form karu

    /** Baris form karu (per mesin / per pengecekan) yang lolos filter dashboard. */
    private function karuBase(string $form, string $child, string $fk, array $f): Builder
    {
        return DB::table("$child as i")
            ->join("$form as f", 'f.id', '=', "i.$fk")
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->whereNull('f.deleted_at')
            ->whereBetween('f.form_date', [$f['from'], $f['to']])
            ->when($f['shift'], fn ($q) => $q->where('f.shift', $f['shift']))
            ->when($f['area_id'], fn ($q) => $q->where('m.area_id', $f['area_id']))
            ->when($f['building'], fn ($q) => $q->where('m.building', $f['building']))
            ->when($f['machine_id'], fn ($q) => $q->where('i.machine_id', $f['machine_id']));
    }

    private function karu(array $f): array
    {
        $items = fn () => $this->karuBase('production_forms', 'production_form_items', 'production_form_id', $f);
        $checks = fn () => $this->karuBase('evaluation_forms', 'evaluation_checks', 'evaluation_form_id', $f);

        $kpi = [
            'production_forms' => (int) $items()->distinct()->count('f.id'),
            'machines' => (int) $items()->count(),
            'evaluation_forms' => (int) $checks()->distinct()->count('f.id'),
            'checks' => (int) $checks()->count(),
            'visual_bad' => (int) $checks()->join('form_options as o', 'o.id', '=', 'i.visual_option_id')->where('o.label', '!=', 'OK')->count(),
            'karus' => (int) DB::query()->fromSub($items()->select('f.user_id')->union($checks()->select('f.user_id')), 'u')->distinct()->count('user_id'),
        ];

        // Problem (Set Up / DT) dari form produksi, menit terbanyak
        $problems = $items()->join('production_form_problems as p', 'p.production_form_item_id', '=', 'i.id')
            ->leftJoin('form_options as o', 'o.id', '=', 'p.form_option_id')
            ->selectRaw('p.kind, o.label, CASE WHEN o.label IS NULL THEN p.other_text END other, SUM(COALESCE(p.minutes,0)) minutes, COUNT(*) n')
            ->groupBy('p.kind', 'o.label', 'other')->orderByDesc('minutes')->get()
            ->each(fn ($r) => $r->name = $r->label ?? 'Lainnya: '.$r->other);
        $total = max(1, $problems->sum('minutes'));
        $cum = 0;
        $pareto = $problems->take(10)->map(function ($r) use ($total, &$cum) {
            $cum += $r->minutes;

            return ['name' => $r->name, 'kind' => $r->kind === 'setup' ? 'Set Up' : 'DT', 'minutes' => (int) $r->minutes, 'count' => (int) $r->n,
                'share' => round($r->minutes / $total * 100, 1), 'cumulative' => round($cum / $total * 100, 1)];
        })->values()->all();

        $dist = fn (string $col) => $checks()->join('form_options as o', 'o.id', '=', "i.$col")
            ->selectRaw('o.label name, o.sort, COUNT(*) n')->groupBy('o.label', 'o.sort')->orderBy('o.sort')->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n])
            ->when(true, function ($c) use ($checks, $col) {
                $other = $checks()->whereNull("i.$col")->whereNotNull('i.'.str_replace('_option_id', '_other', $col))->count();

                return $other ? $c->push(['name' => 'Lainnya', 'count' => $other]) : $c;
            })->values()->all();

        $byKaru = DB::query()->fromSub(
            $items()->selectRaw("f.user_id, 'prod' k")->unionAll($checks()->selectRaw("f.user_id, 'eval' k")), 'x'
        )->join('users as u', 'u.id', '=', 'x.user_id')
            ->selectRaw("u.name, SUM(k = 'prod') prod, SUM(k = 'eval') eval")
            ->groupBy('u.name')->orderByRaw('SUM(1) DESC')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->name, 'prod' => (int) $r->prod, 'eval' => (int) $r->eval])->values()->all();

        return [
            'kpi' => $kpi,
            'problems' => $pareto,
            'visual' => $dist('visual_option_id'),
            'compliance' => $dist('compliance_option_id'),
            'jig' => $dist('jig_option_id'),
            'by_karu' => $byKaru,
            'crosscheck' => $this->karuCrossCheck($f, $items),
        ];
    }

    /**
     * Bandingkan isian karu dengan data import admin (tanggal + shift + mesin yang sama).
     * Selisih besar = perlu dicek di lapangan (downtime tidak tercatat / angka tidak cocok).
     */
    private function karuCrossCheck(array $f, \Closure $items): array
    {
        $rows = $items()
            ->leftJoin('daily_machine_summaries as s', fn ($j) => $j->on('s.production_date', '=', 'f.form_date')
                ->on('s.shift', '=', 'f.shift')->on('s.machine_id', '=', 'i.machine_id'))
            ->leftJoin('users as u', 'u.id', '=', 'f.user_id')
            ->selectRaw('f.id form_id, f.form_date d, f.shift, m.code, u.name karu,
                i.cutting_minutes k_cut, i.ok_qty k_ok,
                (SELECT SUM(COALESCE(p.minutes,0)) FROM production_form_problems p WHERE p.production_form_item_id = i.id) k_dt,
                s.cutting_minutes a_cut, s.downtime_minutes a_dt, s.ok_qty a_ok, s.id s_id')
            ->orderByDesc('f.form_date')->limit(2000)->get();

        $out = [];
        foreach ($rows as $r) {
            $issues = [];
            $score = 0;
            if (! $r->s_id) {
                $issues[] = 'Belum ada data import admin';
                $score = 1000;
            } else {
                $chk = function ($label, $k, $a, $absTol, $relTol) use (&$issues, &$score) {
                    if ($k === null || $a === null) {
                        return;
                    }
                    $diff = (float) $k - (float) $a;
                    if (abs($diff) > max($absTol, abs((float) $a) * $relTol)) {
                        $issues[] = $label;
                        $score += abs($diff) / max(1, abs((float) $a)) * 100;
                    }
                };
                $chk('Downtime beda', $r->k_dt, $r->a_dt, 15, 0.2);
                $chk('Cutting time beda', $r->k_cut, $r->a_cut, 15, 0.1);
                $chk('Hasil OK beda', $r->k_ok, $r->a_ok, 50, 0.1);
            }
            $out[] = [
                'form_id' => $r->form_id, 'date' => substr((string) $r->d, 0, 10), 'shift' => (int) $r->shift, 'machine' => $r->code, 'karu' => $r->karu,
                'karu_dt' => $r->k_dt !== null ? (int) $r->k_dt : null, 'admin_dt' => $r->a_dt !== null ? (int) $r->a_dt : null,
                'karu_cut' => $r->k_cut !== null ? (int) $r->k_cut : null, 'admin_cut' => $r->a_cut !== null ? (int) $r->a_cut : null,
                'karu_ok' => $r->k_ok !== null ? (int) $r->k_ok : null, 'admin_ok' => $r->a_ok !== null ? (int) $r->a_ok : null,
                'issues' => $issues, 'score' => $score,
            ];
        }
        $flagged = collect($out)->filter(fn ($r) => $r['issues'])->sortByDesc('score')->values();

        return [
            'compared' => count($out),
            'matched' => collect($out)->filter(fn ($r) => ! in_array('Belum ada data import admin', $r['issues'], true))->count(),
            'flagged_count' => $flagged->count(),
            'rows' => $flagged->take(20)->map(fn ($r) => collect($r)->except('score')->all() + ['url' => route('forms.production.show', $r['form_id'])])->all(),
        ];
    }

    private function topCustomers(array $f): array
    {
        return $this->base('production_reports', $f, 's')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->selectRaw('c.name, SUM(s.ok_qty) q, SUM(s.ok_kg) kg')->groupBy('c.name')->orderByDesc('q')->limit(8)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->q, 'kg' => round((float) $r->kg)])->values()->all();
    }
}
