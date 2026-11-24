<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Produksi karu per PRO: satu baris = satu PRO (No. Order di Excel) di satu mesin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_form_items', function (Blueprint $table) {
            $table->string('order_no', 20)->nullable()->after('machine_type');
            $table->index('order_no');
        });
    }

    public function down(): void
    {
        Schema::table('production_form_items', function (Blueprint $table) {
            $table->dropIndex(['order_no']);
            $table->dropColumn('order_no');
        });
    }
};
