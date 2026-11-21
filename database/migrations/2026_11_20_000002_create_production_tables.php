<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                       // daily | sap
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('status', 15)->default('preview'); // preview | done | cancelled | failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_replaced')->default(0);
            $table->json('groups')->nullable();               // ringkasan per tanggal+shift
            $table->text('message')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('sheet', 30)->nullable();
            $table->unsignedInteger('row_no')->nullable();
            $table->string('level', 10)->default('error');    // error | warning
            $table->string('message');
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 20)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable()->index();
            // akumulasi dari laporan (untuk yield per order)
            $table->unsignedInteger('report_count')->default(0);
            $table->unsignedBigInteger('ok_qty')->default(0);
            $table->unsignedBigInteger('nc_qty')->default(0);
            $table->unsignedBigInteger('reject_qty')->default(0);
            $table->double('gi_mm')->default(0);
            $table->double('gr_ok_mm')->default(0);
            $table->double('gr_nc_mm')->default(0);
            $table->timestamps();
        });

        // 1 baris = 1 baris Sheet1 + pasangan baris DT
        Schema::create('production_reports', function (Blueprint $table) {
            $table->id();
            $table->date('production_date');
            $table->unsignedTinyInteger('shift');
            $table->foreignId('machine_id')->constrained();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('building', 30)->nullable();
            $table->foreignId('production_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_no', 20)->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('raw_length', 10, 2)->nullable();
            $table->unsignedInteger('raw_qty')->default(0);
            $table->decimal('result_length', 10, 2)->nullable();
            $table->unsignedInteger('ok_qty')->default(0);
            $table->unsignedInteger('nc_qty')->default(0);
            $table->string('nc_note')->nullable();
            $table->string('nc_cause', 80)->nullable();
            $table->string('ncr_no', 30)->nullable();
            $table->unsignedInteger('reject_qty')->default(0);
            $table->string('reject_note')->nullable();
            $table->unsignedInteger('total_cutting_qty')->default(0);
            $table->unsignedInteger('working_minutes')->default(0);
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->unsignedInteger('setup_minutes')->default(0);
            $table->unsignedInteger('cutting_minutes')->default(0);
            $table->decimal('ok_kg', 14, 3)->default(0);
            $table->decimal('nc_kg', 14, 3)->default(0);
            $table->decimal('reject_kg', 14, 3)->default(0);
            $table->decimal('total_kg', 14, 3)->default(0);
            $table->text('downtime_note')->nullable();
            $table->boolean('has_downtime_sheet')->default(true);
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_no')->nullable();
            $table->timestamps();

            $table->index(['production_date', 'shift', 'machine_id']);
            $table->index(['machine_id', 'production_date']);
            $table->index(['customer_id', 'production_date']);
            $table->index(['import_batch_id', 'row_no']);
        });

        Schema::create('production_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('defect_type_id')->constrained();
            $table->unsignedInteger('qty');
            $table->index('defect_type_id');
        });

        Schema::create('production_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('downtime_reason_id')->constrained();
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('frequency')->nullable();
            $table->index('downtime_reason_id');
        });

        // Export SAP (mentahan): No Order, GI bahan (kg), GR hasil (%)
        Schema::create('sap_order_results', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 20)->unique();
            $table->decimal('gi_kg', 16, 3)->nullable();
            $table->decimal('gr_percent', 8, 3)->nullable();
            $table->decimal('gr_kg', 16, 3)->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // ---------- Tabel ringkasan (dibaca dashboard) ----------
        Schema::create('daily_machine_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('production_date');
            $table->unsignedTinyInteger('shift');
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('building', 30)->nullable();
            $table->unsignedInteger('report_count')->default(0);
            $table->unsignedInteger('ok_qty')->default(0);
            $table->unsignedInteger('nc_qty')->default(0);
            $table->unsignedInteger('reject_qty')->default(0);
            $table->unsignedInteger('total_cutting_qty')->default(0);
            $table->unsignedInteger('working_minutes')->default(0);
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->unsignedInteger('setup_minutes')->default(0);
            $table->unsignedInteger('cutting_minutes')->default(0);
            $table->decimal('ok_kg', 16, 3)->default(0);
            $table->decimal('nc_kg', 16, 3)->default(0);
            $table->decimal('reject_kg', 16, 3)->default(0);
            $table->decimal('total_kg', 16, 3)->default(0);
            $table->double('gi_mm')->default(0);
            $table->double('gr_ok_mm')->default(0);
            $table->double('gr_nc_mm')->default(0);
            $table->double('capacity_qty')->default(0);       // std output x jam kerja
            $table->unsignedInteger('capacity_cutting_qty')->default(0); // total cutting baris yg punya std
            $table->unique(['production_date', 'shift', 'machine_id'], 'dms_unique');
            $table->index(['production_date', 'area_id']);
        });

        Schema::create('daily_downtime_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('production_date');
            $table->unsignedTinyInteger('shift');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('building', 30)->nullable();
            $table->unsignedBigInteger('downtime_reason_id');
            $table->unsignedBigInteger('downtime_category_id');
            $table->unsignedInteger('minutes')->default(0);
            $table->unsignedInteger('frequency')->default(0);
            $table->unsignedInteger('occurrences')->default(0);
            $table->index(['production_date', 'shift']);
            $table->index(['production_date', 'downtime_reason_id'], 'dds_date_reason');
        });

        Schema::create('daily_defect_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('production_date');
            $table->unsignedTinyInteger('shift');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('building', 30)->nullable();
            $table->unsignedBigInteger('defect_type_id');
            $table->unsignedInteger('qty')->default(0);
            $table->index(['production_date', 'shift']);
            $table->index(['production_date', 'defect_type_id'], 'dfs_date_type');
        });
    }

    public function down(): void
    {
        foreach (['daily_defect_summaries', 'daily_downtime_summaries', 'daily_machine_summaries',
            'sap_order_results', 'production_downtimes', 'production_defects', 'production_reports',
            'production_orders', 'import_errors', 'import_batches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
