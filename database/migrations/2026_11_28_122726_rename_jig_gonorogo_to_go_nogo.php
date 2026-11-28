<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Opsi jig "JIG GONOROGO" diganti namanya jadi "JIG Go/Nogo" (data lama ikut, karena memakai id opsi yang sama). */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('form_options')->where('group', 'jig')->where('label', 'JIG GONOROGO');
        if (DB::table('form_options')->where('group', 'jig')->where('label', 'JIG Go/Nogo')->exists()) {
            $old->update(['is_active' => false, 'updated_at' => now()]); // sudah ada (database baru): yang lama cukup disembunyikan

            return;
        }
        $old->update(['label' => 'JIG Go/Nogo', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('form_options')->where('group', 'jig')->where('label', 'JIG Go/Nogo')->update(['label' => 'JIG GONOROGO', 'updated_at' => now()]);
    }
};
