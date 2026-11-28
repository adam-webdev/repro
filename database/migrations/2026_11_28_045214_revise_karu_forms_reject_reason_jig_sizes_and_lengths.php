<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi form karu:
 *  - Form Rekapan: alasan reject (wajib bila Reject diisi).
 *  - Form Daily Supervisor: panjang diukur 3 titik (P1, P2, P3). Kolom lama length_mm tetap untuk data lama.
 *  - Jig boleh dicentang lebih dari satu (tabel pivot), JIG In / JIG Out disertai ukuran jig.
 *    Kolom lama jig_option_id tidak dipakai lagi; isinya dipindah ke pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_form_items', function (Blueprint $table) {
            $table->string('reject_reason', 255)->nullable()->after('second_grade_qty');
        });

        Schema::table('evaluation_checks', function (Blueprint $table) {
            $table->decimal('p1', 8, 2)->nullable()->after('length_mm');
            $table->decimal('p2', 8, 2)->nullable()->after('p1');
            $table->decimal('p3', 8, 2)->nullable()->after('p2');
        });

        Schema::create('evaluation_check_jigs', function (Blueprint $table) {
            $table->foreignId('evaluation_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_option_id')->constrained()->cascadeOnDelete();
            $table->string('size', 40)->nullable();
            $table->primary(['evaluation_check_id', 'form_option_id']);
        });

        DB::table('evaluation_checks')->whereNotNull('jig_option_id')->orderBy('id')
            ->select('id', 'jig_option_id')->chunk(500, function ($rows) {
                DB::table('evaluation_check_jigs')->insertOrIgnore($rows->map(fn ($r) => [
                    'evaluation_check_id' => $r->id, 'form_option_id' => $r->jig_option_id, 'size' => null,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_check_jigs');
        Schema::table('evaluation_checks', function (Blueprint $table) {
            $table->dropColumn(['p1', 'p2', 'p3']);
        });
        Schema::table('production_form_items', function (Blueprint $table) {
            $table->dropColumn('reject_reason');
        });
    }
};
