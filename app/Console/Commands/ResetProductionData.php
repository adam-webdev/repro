<?php

namespace App\Console\Commands;

use App\Services\Import\ProductionDataReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Kosongkan data hasil import (untuk uji ulang import dari awal). Isi yang dihapus / tetap: lihat ProductionDataReset.
 * --forms : Form Produksi, Form Evaluasi dan foto lampirannya ikut dihapus.
 */
#[Signature('produksi:reset
    {--forms : Hapus juga Form Produksi, Form Evaluasi dan fotonya}
    {--no-backup : Lewati backup database}
    {--force : Jalankan tanpa pertanyaan konfirmasi}')]
#[Description('Kosongkan data import produksi (akun & master tetap) agar bisa import ulang dari awal')]
class ResetProductionData extends Command
{
    public function handle(ProductionDataReset $reset): int
    {
        $withForms = (bool) $this->option('forms');

        $this->table(['Tabel', 'Baris'], collect($reset->counts($withForms))->map(fn ($n, $t) => [$t, number_format($n, 0, ',', '.')])->values()->all());
        $this->line('Ikut dihapus: customer, material, std output otomatis, file Excel di storage/app/private/imports'
            .($withForms ? ', foto form karu' : ''));
        $this->line('Tetap aman  : akun, karyawan, mesin, area, alasan downtime, jenis reject, pilihan form, log aktivitas'
            .($withForms ? '' : ', Form Produksi & Form Evaluasi'));

        if (! $this->option('force') && ! $this->confirm('Yakin kosongkan data di atas? Tidak bisa dibatalkan (kecuali dari backup).')) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        if (! $this->option('no-backup')) {
            try {
                $file = $reset->backup();
                $file ? $this->info('Backup: '.$file.' ('.round(filesize($file) / 1048576, 1).' MB)') : $this->warn('Backup otomatis hanya untuk MySQL; dilewati.');
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                if (! $this->option('force') && ! $this->confirm('Backup gagal. Tetap lanjut tanpa backup?')) {
                    return self::FAILURE;
                }
            }
        }

        $reset->reset(null, 'php artisan produksi:reset'.($withForms ? ' --forms' : ''), $withForms);
        $this->info('Selesai. Data import kosong; silakan import ulang Excel dari menu Import data.');

        return self::SUCCESS;
    }
}
