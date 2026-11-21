<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Membangun ulang tabel ringkasan harian (yang dibaca dashboard)
 * hanya untuk tanggal yang berubah.
 */
class SummaryBuilder
{
    public function rebuildDates(array $dates): void
    {
        $dates = array_values(array_unique(array_filter($dates)));
        foreach (array_chunk($dates, 60) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));

            DB::transaction(function () use ($chunk, $in) {
                DB::delete("DELETE FROM daily_machine_summaries WHERE production_date IN ($in)", $chunk);
                DB::delete("DELETE FROM daily_downtime_summaries WHERE production_date IN ($in)", $chunk);
                DB::delete("DELETE FROM daily_defect_summaries WHERE production_date IN ($in)", $chunk);

                DB::insert("
                    INSERT INTO daily_machine_summaries
                        (production_date, shift, machine_id, area_id, building, report_count, ok_qty, nc_qty, reject_qty,
                         total_cutting_qty, working_minutes, downtime_minutes, setup_minutes, cutting_minutes,
                         ok_kg, nc_kg, reject_kg, total_kg, gi_mm, gr_ok_mm, gr_nc_mm, capacity_qty, capacity_cutting_qty)
                    SELECT r.production_date, r.shift, r.machine_id, MAX(r.area_id), MAX(r.building), COUNT(*),
                           SUM(r.ok_qty), SUM(r.nc_qty), SUM(r.reject_qty), SUM(r.total_cutting_qty),
                           SUM(r.working_minutes), SUM(r.downtime_minutes), SUM(r.setup_minutes), SUM(r.cutting_minutes),
                           SUM(r.ok_kg), SUM(r.nc_kg), SUM(r.reject_kg), SUM(r.total_kg),
                           SUM(COALESCE(r.raw_length,0) * r.raw_qty),
                           SUM(COALESCE(r.result_length,0) * r.ok_qty),
                           SUM(COALESCE(r.result_length,0) * (r.ok_qty + r.nc_qty)),
                           SUM(CASE WHEN COALESCE(s1.std_pcs_per_hour, s0.std_pcs_per_hour) IS NULL THEN 0
                                    ELSE r.working_minutes * COALESCE(s1.std_pcs_per_hour, s0.std_pcs_per_hour) / 60 END),
                           SUM(CASE WHEN COALESCE(s1.std_pcs_per_hour, s0.std_pcs_per_hour) IS NULL THEN 0
                                    ELSE r.total_cutting_qty END)
                    FROM production_reports r
                    LEFT JOIN machine_standards s1 ON s1.machine_id = r.machine_id AND s1.material_id = r.material_id
                    LEFT JOIN machine_standards s0 ON s0.machine_id = r.machine_id AND s0.material_id IS NULL
                    WHERE r.production_date IN ($in) AND r.deleted_at IS NULL
                    GROUP BY r.production_date, r.shift, r.machine_id
                ", $chunk);

                DB::insert("
                    INSERT INTO daily_downtime_summaries
                        (production_date, shift, machine_id, area_id, building, downtime_reason_id, downtime_category_id,
                         minutes, frequency, occurrences)
                    SELECT r.production_date, r.shift, r.machine_id, MAX(r.area_id), MAX(r.building), d.downtime_reason_id,
                           dr.downtime_category_id, SUM(d.minutes), SUM(COALESCE(d.frequency, 0)), COUNT(*)
                    FROM production_downtimes d
                    JOIN production_reports r ON r.id = d.production_report_id
                    JOIN downtime_reasons dr ON dr.id = d.downtime_reason_id
                    WHERE r.production_date IN ($in) AND r.deleted_at IS NULL
                    GROUP BY r.production_date, r.shift, r.machine_id, d.downtime_reason_id, dr.downtime_category_id
                ", $chunk);

                DB::insert("
                    INSERT INTO daily_defect_summaries
                        (production_date, shift, machine_id, area_id, building, defect_type_id, qty)
                    SELECT r.production_date, r.shift, r.machine_id, MAX(r.area_id), MAX(r.building), d.defect_type_id, SUM(d.qty)
                    FROM production_defects d
                    JOIN production_reports r ON r.id = d.production_report_id
                    WHERE r.production_date IN ($in) AND r.deleted_at IS NULL
                    GROUP BY r.production_date, r.shift, r.machine_id, d.defect_type_id
                ", $chunk);
            });
        }

        self::bumpCache();
    }

    public function rebuildAll(): void
    {
        $dates = DB::table('production_reports')->whereNull('deleted_at')->distinct()->orderBy('production_date')->pluck('production_date')->all();
        $this->rebuildDates($dates);
    }

    /** Hitung ulang akumulasi per order (untuk yield per order). */
    public function refreshOrders(array $orderIds): void
    {
        foreach (array_chunk(array_values(array_unique($orderIds)), 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            DB::update("UPDATE production_orders SET report_count = 0, ok_qty = 0, nc_qty = 0, reject_qty = 0,
                gi_mm = 0, gr_ok_mm = 0, gr_nc_mm = 0 WHERE id IN ($in)", $chunk);
            DB::update("
                UPDATE production_orders o
                JOIN (
                    SELECT production_order_id AS id, COUNT(*) c, MIN(production_date) f, MAX(production_date) l,
                           SUM(ok_qty) ok, SUM(nc_qty) nc, SUM(reject_qty) rj,
                           SUM(COALESCE(raw_length,0) * raw_qty) gi,
                           SUM(COALESCE(result_length,0) * ok_qty) gro,
                           SUM(COALESCE(result_length,0) * (ok_qty + nc_qty)) grn
                    FROM production_reports WHERE production_order_id IN ($in) AND deleted_at IS NULL GROUP BY production_order_id
                ) x ON x.id = o.id
                SET o.report_count = x.c, o.first_date = x.f, o.last_date = x.l, o.ok_qty = x.ok, o.nc_qty = x.nc,
                    o.reject_qty = x.rj, o.gi_mm = x.gi, o.gr_ok_mm = x.gro, o.gr_nc_mm = x.grn
            ", $chunk);
        }
    }

    public static function bumpCache(): void
    {
        Cache::forever('dashboard_version', (int) Cache::get('dashboard_version', 1) + 1);
    }
}
