<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Downtime mengikuti Excel apa adanya: hanya dua kelompok sesuai judul grup di sheet DT ("Set Up" & "Downtime"),
 * dan nama alasan = judul kolom Excel. Kelompok buatan sistem (Setting Proses, Ganti Pahat & Gergaji,
 * Persiapan & Rutin, Kerusakan Mesin, Preventive, Lain-lain, Menunggu) dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('downtime_reasons')->exists()) {
            return; // database baru: cukup jalankan seeder
        }

        DB::transaction(function () {
            $i = 0;
            $cats = [];
            foreach (config('production.downtime_categories') as $key => $name) {
                DB::table('downtime_categories')->updateOrInsert(['key' => $key], ['name' => $name, 'sort' => ++$i, 'updated_at' => now()]);
                $cats[$key] = DB::table('downtime_categories')->where('key', $key)->value('id');
            }

            $i = 0;
            foreach (config('production.downtimes') as $key => $def) {
                DB::table('downtime_reasons')->where('key', $key)->update([
                    'downtime_category_id' => $cats[$def[0]], 'name' => $def[1], 'sort' => ++$i, 'updated_at' => now(),
                ]);
            }

            // ringkasan harian ikut kelompok alasan yang baru
            foreach (DB::table('downtime_reasons')->get(['id', 'downtime_category_id']) as $r) {
                DB::table('daily_downtime_summaries')->where('downtime_reason_id', $r->id)->update(['downtime_category_id' => $r->downtime_category_id]);
            }

            $unused = DB::table('downtime_categories')->whereNotIn('key', array_keys($cats))->pluck('id');
            DB::table('downtime_reasons')->whereIn('downtime_category_id', $unused)->update(['downtime_category_id' => $cats['downtime']]);
            DB::table('daily_downtime_summaries')->whereIn('downtime_category_id', $unused)->update(['downtime_category_id' => $cats['downtime']]);
            DB::table('downtime_categories')->whereIn('id', $unused)->delete();
        });

        Cache::forever('dashboard_version', (int) Cache::get('dashboard_version', 1) + 1);
    }

    public function down(): void
    {
        // Tidak dikembalikan: pengelompokan lama bukan bagian dari Excel.
    }
};
