<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Penyebab NC yang sudah terimport dengan ejaan berbeda (mis. "IB NO REMOVE", "IB TIDAK REMOVE")
 * disamakan ke nama baku di config production.nc_cause_aliases. Teks asli Excel (nc_note) tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (config('production.nc_cause_aliases', []) as $canonical => $aliases) {
            DB::table('production_reports')->whereIn('nc_cause', $aliases)->update(['nc_cause' => $canonical]);
        }

        Cache::forever('dashboard_version', (int) Cache::get('dashboard_version', 1) + 1);
    }

    public function down(): void
    {
        // Tidak dikembalikan: ejaan asli tetap bisa dilihat di kolom nc_note.
    }
};
