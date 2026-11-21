<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('admin')->after('password'); // admin | supervisor | karu
            $table->unsignedTinyInteger('shift')->nullable()->after('role');  // admin per shift (1/2/3)
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('type', 10)->index();          // CUTP, CUTC, DEB, EBR, EXSW, ...
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('building', 30)->nullable();   // Gd Eksisting / Gd Ekspansi
            $table->unsignedTinyInteger('operator_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->timestamps();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->decimal('od_mm', 8, 2)->nullable();
            $table->decimal('thickness_mm', 6, 2)->nullable();
            $table->decimal('length_mm', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('defect_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();          // header Excel yang dinormalisasi
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('downtime_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('downtime_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('downtime_category_id')->constrained();
            $table->string('key', 120)->unique();
            $table->string('name', 100);
            $table->string('frequency_key', 120)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Std output per mesin (+ material opsional). source: auto (median historis) / manual
        Schema::create('machine_standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('std_pcs_per_hour', 12, 2);
            $table->string('source', 10)->default('manual');
            $table->unsignedInteger('sample_count')->default(0);
            $table->timestamps();
            $table->unique(['machine_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_standards');
        Schema::dropIfExists('downtime_reasons');
        Schema::dropIfExists('downtime_categories');
        Schema::dropIfExists('defect_types');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('machines');
        Schema::dropIfExists('areas');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'shift']));
    }
};
