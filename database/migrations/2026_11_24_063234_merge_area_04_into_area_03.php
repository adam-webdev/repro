<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area 04 sekarang digabung ke Area 03: semua mesin, laporan, ringkasan & form karu
 * yang tercatat di Area 04 dipindah ke Area 03, lalu Area 04 dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $from = DB::table('areas')->where('name', 'Area 04')->value('id');
        if (! $from) {
            return;
        }

        DB::transaction(function () use ($from) {
            $to = DB::table('areas')->where('name', 'Area 03')->value('id')
                ?? DB::table('areas')->insertGetId(['name' => 'Area 03', 'created_at' => now(), 'updated_at' => now()]);

            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                if ($table !== 'areas' && Schema::hasColumn($table, 'area_id')) {
                    DB::table($table)->where('area_id', $from)->update(['area_id' => $to]);
                }
            }

            DB::table('areas')->where('id', $from)->delete();
        });

        Cache::forever('dashboard_version', (int) Cache::get('dashboard_version', 1) + 1);
    }

    public function down(): void
    {
        // Tidak dikembalikan: mesin asal Area 04 tidak lagi bisa dibedakan setelah digabung.
    }
};
