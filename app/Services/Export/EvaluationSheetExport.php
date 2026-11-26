<?php

namespace App\Services\Export;

use App\Models\EvaluationCheck;
use App\Models\EvaluationForm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Isi template kertas PDR/PP/13 (resources/templates/pdr-pp-13.xlsx) dengan data Form Daily Supervisor:
 * satu form, atau gabungan semua karu pada satu tanggal + area.
 * Satu lembar memuat 15 pengecekan (baris 11–40, dua baris per pengecekan); kalau lebih, lembar diduplikasi.
 * Foto tidak ditempel: link fotonya ditulis di kolom keterangan ("Foto: link").
 */
class EvaluationSheetExport
{
    public const TEMPLATE = 'templates/pdr-pp-13.xlsx';

    public const PER_PAGE = 15;

    private const FIRST_ROW = 11;

    private const TICK = '✓';

    public function build(EvaluationForm $form): Spreadsheet
    {
        $form->loadMissing(['user.employee', 'area', 'checks.machine', 'checks.customer', 'checks.jig', 'checks.visual', 'checks.compliances', 'checks.attachments', 'checks.pipePhotos']);
        $karu = $form->user?->name;
        if ($code = $form->user?->employee?->code) {
            $karu .= ' ('.$code.')';
        }
        if ($sub = $form->substituteLabel()) {
            $karu .= ' · '.$sub;
        }

        return $this->fill(
            ['date' => $form->form_date, 'shift' => (string) $form->shift, 'karu' => $karu, 'area' => $form->area?->name ?? 'Semua area'],
            $form->checks->sortBy('seq')->values(),
            'PDR/PP/13 '.$form->form_date->format('d-m-Y').' Shift '.$form->shift,
            false,
        );
    }

    /**
     * Gabungan semua karu pada satu tanggal + area; $checks sudah urut dan memuat relasi form.user.
     *
     * @param  Collection<int, EvaluationCheck>  $checks
     */
    public function buildCombined(Carbon $date, string $areaName, Collection $checks): Spreadsheet
    {
        $forms = $checks->pluck('form')->unique('id');

        return $this->fill(
            [
                'date' => $date,
                'shift' => $forms->pluck('shift')->unique()->sort()->join(', '),
                'karu' => $forms->map(fn ($f) => $f->user ? $f->karuLabel() : null)->filter()->unique()->sort()->join(', '),
                'area' => $areaName,
            ],
            $checks->values(),
            'PDR/PP/13 '.$date->format('d-m-Y').' '.$areaName,
            $forms->count() > 1,
        );
    }

    public function combinedFilename(Carbon $date, string $areaName): string
    {
        return sprintf('PDR-PP-13_%s_%s.xlsx', $date->format('Y-m-d'), trim(preg_replace('/[^A-Za-z0-9]+/', '-', $areaName), '-'));
    }

    /**
     * @param  array{date: Carbon, shift: string, karu: ?string, area: string}  $header
     * @param  Collection<int, EvaluationCheck>  $checks
     */
    private function fill(array $header, Collection $checks, string $title, bool $withKaru): Spreadsheet
    {
        $book = IOFactory::load(resource_path(self::TEMPLATE));
        $master = $book->getSheet(0);
        // buang lembar kosong bawaan template (Sheet1–Sheet5)
        while ($book->getSheetCount() > 1) {
            $book->removeSheetByIndex(1);
        }

        $chunks = $checks->chunk(self::PER_PAGE);
        if ($chunks->isEmpty()) {
            $chunks = collect([collect()]);
        }
        $pages = $chunks->count();

        // siapkan lembar tambahan dari template yang masih kosong
        $sheets = [$master];
        for ($p = 2; $p <= $pages; $p++) {
            $copy = clone $master;
            $copy->setTitle('Hal '.$p);
            $book->addSheet($copy);
            $sheets[] = $copy;
        }
        $master->setTitle('Hal 1');

        foreach ($chunks->values() as $i => $pageChecks) {
            $this->fillHeader($sheets[$i], $header, $i + 1, $pages);
            foreach ($pageChecks->values() as $slot => $check) {
                $this->fillCheck($sheets[$i], self::FIRST_ROW + $slot * 2, $check, $withKaru);
            }
            $sheets[$i]->getPageSetup()->setPrintArea('A1:P42');
        }
        $book->setActiveSheetIndex(0);
        $book->getProperties()->setTitle($title)->setCreator(config('app.name'));

        return $book;
    }

    public function filename(EvaluationForm $form): string
    {
        $karu = preg_replace('/[^A-Za-z0-9]+/', '-', $form->user?->employee?->code ?? $form->user?->name ?? 'karu');

        return sprintf('PDR-PP-13_%s_Shift%d_%s.xlsx', $form->form_date->format('Y-m-d'), $form->shift, trim($karu, '-'));
    }

    /** @param  array{date: Carbon, shift: string, karu: ?string, area: string}  $h */
    private function fillHeader(Worksheet $ws, array $h, int $page, int $pages): void
    {
        $ws->setCellValue('D5', $h['date']->translatedFormat('d F Y'));
        $ws->setCellValueExplicit('D6', $h['shift'], 's');
        $ws->getStyle('D6')->getAlignment()->setHorizontal('left');
        $ws->setCellValue('N5', $h['karu']);
        $ws->getStyle('N5')->getAlignment()->setWrapText(true);
        $ws->setCellValue('N6', $h['area']);
        $ws->setCellValue('P3', ": {$page} dari {$pages}");
    }

    private function fillCheck(Worksheet $ws, int $r, EvaluationCheck $c, bool $withKaru = false): void
    {
        $r2 = $r + 1;
        $num = fn ($v) => $v === null ? null : (float) $v;

        if ($c->check_time) {
            [$h, $m] = array_map('intval', explode(':', $c->check_time));
            $ws->setCellValue("A{$r}", ($h * 60 + $m) / 1440); // jam Excel = pecahan hari
            $ws->getStyle("A{$r}")->getNumberFormat()->setFormatCode('hh:mm');
        }
        $ws->setCellValue("B{$r}", $c->machine?->code);
        $ws->setCellValue("C{$r}", 'Cust : '.($c->customer?->name ?? ''));
        $ws->setCellValue("C{$r2}", 'Size : '.($c->dimension ?? ''));

        foreach (['D' => $c->d1, 'E' => $c->d2, 'F' => $c->d3, 'G' => $c->d4, 'H' => $c->thickness_mm, 'I' => $c->ib_height_mm, 'J' => $c->length_mm] as $col => $v) {
            $ws->setCellValue("{$col}{$r}", $num($v));
        }

        // Jig: lembar kertas punya dua kotak, "In/Out" dan "Panjang"; pilihan lain dicatat di kolom keterangan
        $notes = [];
        if ($withKaru) {
            $notes[] = 'Karu: '.($c->form?->karuLabel() ?? '-').' · Shift '.$c->form?->shift;
        }
        $jig = $c->jig?->label;
        if ($jig && preg_match('/jig (in|out)|in\/out/i', $jig)) {
            $ws->setCellValue("K{$r}", 'In/Out : '.self::TICK.(preg_match('/jig (in|out)$/i', $jig, $m) ? ' '.ucfirst(strtolower($m[1])) : ''));
        } elseif ($jig && stripos($jig, 'panjang') !== false) {
            $ws->setCellValue("K{$r2}", 'Panjang : '.self::TICK);
        } elseif ($jig || $c->jig_other) {
            $notes[] = 'Jig: '.($jig ?? $c->jig_other);
        }

        $ws->setCellValue("L{$r}", $c->visual?->label ?? $c->visual_other);
        $ws->setCellValue("M{$r}", $c->actual_cutting);
        $compliance = $c->complianceText();
        $ws->setCellValue("N{$r}", $compliance ? self::TICK.' '.$compliance : null);

        // Kolom planning tidak ada di lembar kertas: dicatat di keterangan
        if ($c->planned_start || $c->planned_qty !== null) {
            $notes[] = 'Planning: '.trim(($c->planned_start ? 'start '.substr($c->planned_start, 0, 5) : '').($c->planned_qty !== null ? ' · '.number_format($c->planned_qty, 0, ',', '.').' pcs' : ''), ' ·');
        }

        if ($c->od_mm !== null) {
            $notes[] = 'OD: '.rtrim(rtrim(number_format((float) $c->od_mm, 3, ',', ''), '0'), ',');
        }
        if ($c->problem) {
            $notes[] = $c->problem;
        }
        foreach ($c->photoLinks() as $url) {
            $notes[] = 'Foto: '.$url;
        }
        foreach ($c->pipePhotoLinks() as $url) {
            $notes[] = 'Foto visual pipa: '.$url;
        }
        $ws->setCellValue("P{$r}", $notes ? implode("\n", $notes) : null);

        foreach (["C{$r}", "C{$r2}", "N{$r}", "P{$r}", "L{$r}"] as $cell) {
            $ws->getStyle($cell)->getAlignment()->setWrapText(true)->setVertical('center');
        }
        foreach (['A', 'B', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'L', 'M'] as $col) {
            $ws->getStyle("{$col}{$r}")->getAlignment()->setHorizontal('center')->setVertical('center');
        }
    }
}
