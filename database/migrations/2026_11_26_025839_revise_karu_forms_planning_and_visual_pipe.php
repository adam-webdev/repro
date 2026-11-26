<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi form karu:
 *  - Start jam planning & qty planning pindah dari Form Daily Supervisor (evaluasi) ke Form Rekapan (produksi) per PRO.
 *    Kolom lama di evaluation_checks dibiarkan supaya data lama tetap bisa dilihat.
 *  - Kontrol kepatuhan "Pengecekan visual pipa": wajib foto / link gambar (attachments.purpose = visual_pipe, evaluation_checks.pipe_photo_url).
 *  - Nama kolom DT "Tunggu Kep QC, Verifikasi LT" cukup "Verifikasi LT" (key import tidak berubah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_form_items', function (Blueprint $table) {
            $table->time('planned_start')->nullable()->after('dimension');
            $table->unsignedInteger('planned_qty')->nullable()->after('planned_start');
        });

        Schema::table('evaluation_checks', function (Blueprint $table) {
            $table->string('pipe_photo_url', 500)->nullable()->after('compliance_other');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable()->after('attachable_id');
        });

        if (DB::table('form_options')->where('group', 'compliance')->exists()) {
            DB::table('form_options')->updateOrInsert(
                ['group' => 'compliance', 'machine_type' => null, 'label' => 'Pengecekan visual pipa'],
                ['sort' => (int) DB::table('form_options')->where('group', 'compliance')->max('sort') + 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        DB::table('downtime_reasons')->where('key', 'tunggu kep qc verifikasi lt')->update(['name' => 'Verifikasi LT']);
        Cache::forever('dashboard_version', (int) Cache::get('dashboard_version', 1) + 1);
    }

    public function down(): void
    {
        DB::table('downtime_reasons')->where('key', 'tunggu kep qc verifikasi lt')->update(['name' => 'Tunggu Kep QC, Verifikasi LT']);
        DB::table('form_options')->where('group', 'compliance')->where('label', 'Pengecekan visual pipa')->delete();

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
        Schema::table('evaluation_checks', function (Blueprint $table) {
            $table->dropColumn('pipe_photo_url');
        });
        Schema::table('production_form_items', function (Blueprint $table) {
            $table->dropColumn(['planned_start', 'planned_qty']);
        });
    }
};
