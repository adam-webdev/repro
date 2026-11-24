<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kategori "Menunggu" bukan kategori dari Excel; isinya kolom "Tunggu MTC / PPIC / QC / Bahan / Asah Gergaji / Box".
 * Sementara digabung ke "Lain-lain" (alasan detailnya tetap tersimpan apa adanya).
 */
return new class extends Migration
{
    public function up(): void
    {
        $wait = DB::table('downtime_categories')->where('key', 'menunggu')->value('id');
        if (! $wait) {
            return;
        }
        $other = DB::table('downtime_categories')->where('key', 'lain')->value('id')
            ?? DB::table('downtime_categories')->insertGetId(['key' => 'lain', 'name' => 'Lain-lain', 'sort' => 99, 'created_at' => now(), 'updated_at' => now()]);

        DB::transaction(function () use ($wait, $other) {
            DB::table('downtime_reasons')->where('downtime_category_id', $wait)->update(['downtime_category_id' => $other]);
            DB::table('daily_downtime_summaries')->where('downtime_category_id', $wait)->update(['downtime_category_id' => $other]);
            DB::table('downtime_categories')->where('id', $wait)->delete();

            // urutan kategori mengikuti config (menentukan urutan warna grafik)
            $i = 0;
            foreach (array_keys(config('production.downtime_categories')) as $key) {
                DB::table('downtime_categories')->where('key', $key)->update(['sort' => ++$i]);
            }
        });

        Cache::forever('dashboard_version', (int) Cache::get('dashboard_version', 1) + 1);
    }

    public function down(): void
    {
        // Tidak dikembalikan: pemisahan ulang cukup dengan mengubah config lalu menjalankan seeder.
    }
};
