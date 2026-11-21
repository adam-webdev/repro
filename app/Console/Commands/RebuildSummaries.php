<?php

namespace App\Console\Commands;

use App\Services\StandardBaseline;
use App\Services\SummaryBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('report:rebuild {--baseline : Hitung ulang std output otomatis dulu}')]
#[Description('Bangun ulang semua tabel ringkasan dashboard')]
class RebuildSummaries extends Command
{
    public function handle(SummaryBuilder $summaries, StandardBaseline $baseline): int
    {
        if ($this->option('baseline')) {
            $this->info($baseline->run().' std output otomatis dibuat.');
        }
        $summaries->rebuildAll();
        $this->info('Ringkasan selesai dibangun ulang.');

        return self::SUCCESS;
    }
}
