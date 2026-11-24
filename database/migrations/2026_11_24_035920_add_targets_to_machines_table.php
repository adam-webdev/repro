<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target per mesin untuk halaman Monitoring Karu (Monitoring Daily).
 * Kosong = pakai bawaan: efficiency dari config production.monitoring.target_efficiency,
 * target harian dari Std Output × waktu kerja di Form Produksi karu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->decimal('target_efficiency', 5, 2)->nullable()->after('building'); // %
            $table->unsignedInteger('daily_target_pcs')->nullable()->after('target_efficiency');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['target_efficiency', 'daily_target_pcs']);
        });
    }
};
