<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area tugas karu & karu pengganti:
 *  - users.area_id: karu tetap hanya bisa input di area ini (kosong = bebas, mis. karu Repair / Packing).
 *  - users.is_substitute: karu pengganti, bisa input di semua area; akun baru harus diaktifkan Admin Master.
 *  - form *.by_substitute & substitute_for_id: tercatat di setiap form siapa yang menggantikan siapa.
 */
return new class extends Migration
{
    /** Penempatan awal 6 karu Cutting (username => nama area). */
    private const INITIAL_AREAS = [
        'KA072' => 'Area 01', 'KA044' => 'Area 01',
        'KA033' => 'Area 02', 'KA155' => 'Area 02',
        'KA054' => 'Area 03', 'KA078' => 'Area 03',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('role')->constrained('areas')->nullOnDelete();
            $table->boolean('is_substitute')->default(false)->after('area_id');
        });

        foreach (['production_forms', 'evaluation_forms'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('by_substitute')->default(false)->after('employee_id');
                $table->foreignId('substitute_for_id')->nullable()->after('by_substitute')->constrained('users')->nullOnDelete();
            });
        }

        $areas = DB::table('areas')->pluck('id', 'name');
        foreach (self::INITIAL_AREAS as $username => $area) {
            if (isset($areas[$area])) {
                DB::table('users')->where('username', $username)->where('role', 'karu')->update(['area_id' => $areas[$area]]);
            }
        }
    }

    public function down(): void
    {
        foreach (['production_forms', 'evaluation_forms'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('substitute_for_id');
                $table->dropColumn('by_substitute');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn('is_substitute');
        });
    }
};
