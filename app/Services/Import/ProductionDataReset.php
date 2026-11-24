<?php

namespace App\Services\Import;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\User;
use App\Services\SummaryBuilder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Kosongkan data hasil import Excel (untuk uji ulang import dari awal). Dipakai perintah
 * `php artisan produksi:reset` dan tombol "Kosongkan data produksi" di menu Import (Admin Master).
 *
 * Dihapus : hasil produksi + downtime + reject, ringkasan dashboard, order, customer, material,
 *           data SAP, riwayat import, std output otomatis, file Excel yang pernah diupload.
 * Tetap   : akun & karyawan, master mesin/area, daftar alasan downtime & jenis reject,
 *           pilihan form karu, std output yang diisi manual, log aktivitas.
 * $withForms: Form Produksi, Form Evaluasi dan foto lampirannya ikut dihapus.
 */
class ProductionDataReset
{
    /** Urutan aman terhadap foreign key: anak dulu. */
    public const IMPORT_TABLES = [
        'production_downtimes', 'production_defects', 'production_reports',
        'daily_machine_summaries', 'daily_downtime_summaries', 'daily_defect_summaries',
        'sap_order_results', 'production_orders', 'import_errors', 'import_batches',
    ];

    public const FORM_TABLES = [
        'production_form_problems', 'production_form_items', 'production_forms',
        'evaluation_checks', 'evaluation_forms',
    ];

    /** @return list<string> */
    public static function tables(bool $withForms = false): array
    {
        return $withForms ? array_merge(self::FORM_TABLES, self::IMPORT_TABLES) : self::IMPORT_TABLES;
    }

    /** @return array<string, int> jumlah baris per tabel yang akan dihapus */
    public function counts(bool $withForms = false): array
    {
        return collect(self::tables($withForms))->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    }

    /**
     * Hapus data. Memakai kunci yang sama dengan proses import supaya tidak bentrok dengan import yang sedang disimpan.
     *
     * @return array<string, int> jumlah baris per tabel sebelum dihapus
     */
    public function reset(?User $user, string $reason, bool $withForms = false): array
    {
        try {
            return Cache::lock('production-import-write', 900)->block(120, fn () => $this->run($user, $reason, $withForms));
        } catch (LockTimeoutException) {
            throw new RuntimeException('Ada import yang sedang disimpan. Tunggu sebentar lalu coba lagi.');
        }
    }

    /**
     * mysqldump ke storage/backups. Mengembalikan path file; null bila bukan MySQL (backup dilewati).
     *
     * @throws RuntimeException bila backup gagal
     */
    public function backup(): ?string
    {
        $c = config('database.connections.'.config('database.default'));
        if (($c['driver'] ?? null) !== 'mysql') {
            return null;
        }
        $dir = storage_path('backups');
        is_dir($dir) || mkdir($dir, 0775, true);
        $file = $dir.DIRECTORY_SEPARATOR.$c['database'].'_sebelum-reset_'.now()->format('Ymd_His').'.sql';

        try {
            $result = Process::env(['MYSQL_PWD' => (string) $c['password']])->timeout(600)->run([
                config('production.mysqldump_path') ?: 'mysqldump', '--single-transaction', '--quick', '--no-tablespaces',
                '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], '--result-file='.$file, $c['database'],
            ]);
        } catch (Throwable $e) {
            // mis. aaPanel: proc_open ada di "Disabled functions" PHP
            @unlink($file);
            throw new RuntimeException('Backup gagal: server tidak mengizinkan menjalankan mysqldump ('.$e->getMessage().'). '
                .'Hapus proc_open dari Disabled functions PHP di aaPanel.');
        }
        if (! $result->successful() || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new RuntimeException('Backup gagal: '.trim($result->errorOutput() ?: 'mysqldump tidak ditemukan (isi MYSQLDUMP_PATH di .env).'));
        }

        return $file;
    }

    /** @return array<string, int> */
    private function run(?User $user, string $reason, bool $withForms): array
    {
        $tables = self::tables($withForms);
        $counts = $this->counts($withForms);
        $files = $withForms ? Attachment::pluck('path')->all() : [];

        DB::transaction(function () use ($tables, $withForms) {
            foreach ($tables as $t) {
                DB::table($t)->delete();
            }
            if ($withForms) {
                DB::table('attachments')->delete();
            }
            DB::table('machine_standards')->where('source', '!=', 'manual')->delete();
            // customer dari form evaluasi & material dari std manual dipertahankan
            DB::table('customers')->whereNotIn('id', DB::table('evaluation_checks')->whereNotNull('customer_id')->select('customer_id'))->delete();
            DB::table('materials')->whereNotIn('id', DB::table('machine_standards')->whereNotNull('material_id')->select('material_id'))->delete();
        });

        Storage::disk('local')->deleteDirectory('imports');
        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }
        SummaryBuilder::bumpCache();

        ActivityLog::record('delete', ['production_data', null, 'Reset data import'.($withForms ? ' + form karu' : '')], [
            'old' => ['Laporan produksi' => $counts['production_reports'], 'Riwayat import' => $counts['import_batches'], 'Alasan' => $reason],
            'new' => [],
        ], $user ?? User::where('role', 'master')->orderBy('id')->first());

        return $counts;
    }
}
