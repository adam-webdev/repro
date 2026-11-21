<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use App\Services\StandardBaseline;
use App\Services\SummaryBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('report:import {file : Path file Excel (laporan harian atau export SAP)} {--baseline : Hitung ulang std output otomatis setelah import}')]
#[Description('Import file Excel lewat terminal (cocok untuk data historis yang besar)')]
class ImportExcel extends Command
{
    public function handle(ImportService $service, StandardBaseline $baseline, SummaryBuilder $summaries): int
    {
        ini_set('memory_limit', '2048M');
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $stored = 'imports/'.now()->format('Ymd_His').'_'.basename($file);
        Storage::disk('local')->put($stored, file_get_contents($file));

        $batch = ImportBatch::create(['type' => 'daily', 'file_name' => basename($file), 'file_path' => $stored, 'status' => 'preview']);
        $this->info('Membaca & memvalidasi file...');
        $t = microtime(true);
        $service->preview($batch);

        if ($batch->status === 'failed') {
            $this->error($batch->message);

            return self::FAILURE;
        }

        $this->line(sprintf('Tipe: %s | baris valid: %d | error: %d | peringatan: %d (%.1fs)',
            $batch->type, $batch->rows_valid, $batch->rows_error,
            $batch->errors()->where('level', 'warning')->count(), microtime(true) - $t));

        foreach ($batch->errors()->where('level', 'error')->limit(10)->get() as $e) {
            $this->warn("  [{$e->sheet} baris {$e->row_no}] {$e->message}");
        }

        $t = microtime(true);
        $this->info('Menyimpan ke database...');
        $service->commit($batch, null, force: true);
        $this->info(sprintf('Selesai: %d baris diimport, %d baris lama diganti (%.1fs)', $batch->rows_imported, $batch->rows_replaced, microtime(true) - $t));

        if ($this->option('baseline') && $batch->type === 'daily') {
            $this->info('Menghitung std output otomatis (median historis)...');
            $n = $baseline->run();
            $summaries->rebuildAll();
            $this->info("{$n} std output dibuat, ringkasan dibangun ulang.");
        }

        return self::SUCCESS;
    }
}
