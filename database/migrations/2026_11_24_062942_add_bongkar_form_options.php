<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Form Produksi karu: kelompok "Bongkar" (setelah Set Up) dengan 2 pilihan —
 * Bongkar Pasang Cekam & Lain-lain. Dihitung sebagai downtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('form_options')->exists()) {
            return; // database baru: cukup jalankan seeder
        }

        $reasons = DB::table('downtime_reasons')->pluck('id', 'key');
        $i = 0;
        foreach (config('production.form_options.bongkar') as $label => $key) {
            DB::table('form_options')->updateOrInsert(
                ['group' => 'bongkar', 'machine_type' => null, 'label' => $label],
                ['downtime_reason_id' => $reasons[$key] ?? null, 'sort' => ++$i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('form_options')->where('group', 'bongkar')->delete();
    }
};
