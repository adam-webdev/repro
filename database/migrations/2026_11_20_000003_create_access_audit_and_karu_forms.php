<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- karyawan & akun
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();      // Kode Karu, mis. "KA 044"
            $table->string('name', 100);
            $table->string('id_card', 50)->nullable()->unique();
            $table->char('gender', 1)->nullable();                  // L / P
            $table->string('position', 60)->nullable();             // Jabatan
            $table->string('email')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->foreignId('employee_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // peran lama -> peran baru
        DB::table('users')->where('role', 'admin')->update(['role' => 'produksi']);
        // akun "supervisor" lama dijadikan Admin Master default (riwayat import-nya tetap tersambung)
        DB::table('users')->where('role', 'supervisor')->where('email', 'supervisor@produksi.local')
            ->update(['role' => 'master', 'username' => 'admin', 'name' => 'Admin Master', 'must_change_password' => true]);
        DB::table('users')->where('role', 'supervisor')->update(['role' => 'master']);
        foreach (DB::table('users')->whereNull('username')->get(['id', 'email']) as $u) {
            DB::table('users')->where('id', $u->id)->update(['username' => strtok((string) $u->email, '@') ?: 'user'.$u->id]);
        }

        // ---------------------------------------------------------------- audit trail
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 100)->nullable();     // salinan saat kejadian
            $table->string('user_role', 20)->nullable();
            $table->string('user_position', 60)->nullable();
            $table->string('action', 20);                      // create | update | delete | restore
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_key', 64)->nullable();     // mis. "2026-11-14|1" untuk data import per tanggal+shift
            $table->string('subject_label', 191)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_key']);
            $table->index('created_at');
            $table->index('user_id');
        });

        // ---------------------------------------------------------------- data import: jejak & soft delete
        Schema::table('production_reports', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE production_reports r JOIN import_batches b ON b.id = r.import_batch_id
                SET r.created_by = b.user_id, r.updated_by = b.user_id');
        }

        // ---------------------------------------------------------------- pilihan dropdown form karu
        Schema::create('form_options', function (Blueprint $table) {
            $table->id();
            $table->string('group', 20);                 // setup | dt | compliance | visual | jig
            $table->string('machine_type', 10)->nullable(); // null = umum, laser, exs (khusus DT)
            $table->string('label', 120);
            $table->foreignId('downtime_reason_id')->nullable()->constrained()->nullOnDelete(); // padanan kolom DT di Excel
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['group', 'machine_type']);
        });

        // ---------------------------------------------------------------- Form Produksi (rutin)
        Schema::create('production_forms', function (Blueprint $table) {
            $table->id();
            $table->date('form_date');
            $table->unsignedTinyInteger('shift');
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();           // karu yang mengisi
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['form_date', 'shift']);
        });

        Schema::create('production_form_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained();
            $table->string('machine_type', 10)->default('umum');   // umum | laser | exs
            $table->string('dimension', 60)->nullable();
            $table->unsignedSmallInteger('working_minutes')->nullable();
            $table->unsignedSmallInteger('cutting_minutes')->nullable();
            $table->decimal('std_output', 10, 2)->nullable();       // pcs/jam
            $table->decimal('act_output', 10, 2)->nullable();       // pcs/jam
            $table->unsignedInteger('ok_qty')->nullable();
            $table->unsignedInteger('second_grade_qty')->nullable();
            $table->unsignedInteger('nc_qty')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->index('machine_id');
        });

        Schema::create('production_form_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_form_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                              // setup | dt
            $table->foreignId('form_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('other_text', 150)->nullable();           // diisi jika "Lainnya"
            $table->unsignedSmallInteger('minutes')->nullable();
        });

        // ---------------------------------------------------------------- Form Evaluasi (Daily Supervisory Control)
        Schema::create('evaluation_forms', function (Blueprint $table) {
            $table->id();
            $table->date('form_date');
            $table->unsignedTinyInteger('shift');
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['form_date', 'shift']);
        });

        Schema::create('evaluation_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_form_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->time('check_time')->nullable();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dimension', 60)->nullable();
            $table->decimal('od_mm', 8, 2)->nullable();
            $table->decimal('d1', 8, 2)->nullable();
            $table->decimal('d2', 8, 2)->nullable();
            $table->decimal('d3', 8, 2)->nullable();
            $table->decimal('d4', 8, 2)->nullable();
            $table->decimal('thickness_mm', 6, 2)->nullable();
            $table->decimal('ib_height_mm', 8, 2)->nullable();
            $table->decimal('length_mm', 8, 1)->nullable();
            $table->foreignId('jig_option_id')->nullable()->constrained('form_options')->nullOnDelete();
            $table->string('jig_other', 150)->nullable();
            $table->foreignId('visual_option_id')->nullable()->constrained('form_options')->nullOnDelete();
            $table->string('visual_other', 150)->nullable();
            $table->foreignId('compliance_option_id')->nullable()->constrained('form_options')->nullOnDelete();
            $table->string('compliance_other', 150)->nullable();
            $table->unsignedInteger('actual_cutting')->nullable();
            $table->text('problem')->nullable();
            $table->index('machine_id');
        });

        // ---------------------------------------------------------------- foto bukti
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('attachable');
            $table->foreignId('user_id')->constrained();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 50)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('evaluation_checks');
        Schema::dropIfExists('evaluation_forms');
        Schema::dropIfExists('production_form_problems');
        Schema::dropIfExists('production_form_items');
        Schema::dropIfExists('production_forms');
        Schema::dropIfExists('form_options');
        Schema::table('production_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
        Schema::dropIfExists('activity_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['username', 'is_active', 'must_change_password', 'last_login_at', 'deleted_at']);
        });
        Schema::dropIfExists('employees');
    }
};
