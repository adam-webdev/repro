<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Form Evaluasi:
 *  - Start jam planning & qty planning per baris pengecekan (dipakai Monitoring Karu).
 *  - Kontrol kepatuhan boleh dicentang lebih dari satu (tabel pivot). Kolom lama compliance_option_id
 *    tidak dipakai lagi; isinya dipindah ke pivot.
 *  - JIG: "JIG In/Out" dipisah jadi "JIG In" & "JIG Out" (opsi lama dinonaktifkan, data lama tetap terbaca),
 *    "JIG GRONOGO" diganti "JIG GONOROGO".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_checks', function (Blueprint $table) {
            $table->time('planned_start')->nullable()->after('dimension');
            $table->unsignedInteger('planned_qty')->nullable()->after('actual_cutting');
        });

        Schema::create('evaluation_check_compliances', function (Blueprint $table) {
            $table->foreignId('evaluation_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_option_id')->constrained()->cascadeOnDelete();
            $table->primary(['evaluation_check_id', 'form_option_id']);
        });

        DB::table('evaluation_checks')->whereNotNull('compliance_option_id')->orderBy('id')
            ->select('id', 'compliance_option_id')->chunk(500, function ($rows) {
                DB::table('evaluation_check_compliances')->insertOrIgnore($rows->map(fn ($r) => [
                    'evaluation_check_id' => $r->id, 'form_option_id' => $r->compliance_option_id,
                ])->all());
            });

        DB::table('form_options')->where('group', 'jig')->where('label', 'JIG GRONOGO')->update(['label' => 'JIG GONOROGO']);
        DB::table('form_options')->where('group', 'jig')->where('label', 'JIG In/Out')->update(['is_active' => false]);
        foreach (config('production.form_options.jig') as $i => $label) {
            $exists = DB::table('form_options')->where('group', 'jig')->whereNull('machine_type')->where('label', $label)->exists();
            if ($exists) {
                DB::table('form_options')->where('group', 'jig')->whereNull('machine_type')->where('label', $label)->update(['sort' => $i + 1, 'is_active' => true]);
            } elseif (DB::table('form_options')->where('group', 'jig')->exists()) {
                DB::table('form_options')->insert(['group' => 'jig', 'label' => $label, 'sort' => $i + 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_check_compliances');
        Schema::table('evaluation_checks', function (Blueprint $table) {
            $table->dropColumn(['planned_start', 'planned_qty']);
        });
        DB::table('form_options')->where('group', 'jig')->where('label', 'JIG GONOROGO')->update(['label' => 'JIG GRONOGO']);
        DB::table('form_options')->where('group', 'jig')->where('label', 'JIG In/Out')->update(['is_active' => true]);
        DB::table('form_options')->where('group', 'jig')->whereIn('label', ['JIG In', 'JIG Out'])->update(['is_active' => false]);
    }
};
