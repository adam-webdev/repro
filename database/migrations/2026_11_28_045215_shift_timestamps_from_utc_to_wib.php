<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zona waktu aplikasi pindah dari UTC ke WIB (Asia/Jakarta, UTC+7).
 * Semua kolom timestamp yang selama ini ditulis aplikasi dalam UTC digeser +7 jam supaya jam yang tampil
 * tetap sama dengan kejadian sebenarnya. Hanya untuk MySQL / MariaDB (database pengujian dibuat ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('+');
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = DB::select(
            "select table_name as t, column_name as c from information_schema.columns
             where table_schema = database() and data_type in ('timestamp', 'datetime') and table_name <> 'migrations'"
        );
        foreach (collect($columns)->groupBy('t') as $table => $cols) {
            $sets = $cols->map(fn ($r) => "`{$r->c}` = IF(`{$r->c}` IS NULL, NULL, `{$r->c}` {$sign} INTERVAL 7 HOUR)")->implode(', ');
            DB::statement("UPDATE `{$table}` SET {$sets}");
        }
    }
};
