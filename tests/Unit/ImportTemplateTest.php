<?php

namespace Tests\Unit;

use App\Services\Import\ExcelValue;
use App\Services\Import\TemplateBuilder;
use App\Services\Import\WorkbookParser;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Template import harus selalu cocok dengan pembaca Excel:
 * setiap nama kolom dikenali, dan file contoh terbaca tanpa error.
 */
class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function knownHeaders(): array
    {
        $fields = (new \ReflectionClassConstant(WorkbookParser::class, 'FIELDS'))->getValue();
        $known = ['area'];
        foreach ($fields as $aliases) {
            $known = array_merge($known, $aliases);
        }
        foreach (config('production.downtimes') as $key => $def) {
            $known[] = $key;
            if (isset($def[2])) {
                $known[] = $def[2];
            }
        }

        return array_merge($known, array_keys(config('production.defects')), config('production.downtime_notes'));
    }

    public function test_every_template_column_is_recognised_by_the_parser(): void
    {
        $known = $this->knownHeaders();
        foreach (array_merge(TemplateBuilder::resultHeaders(), TemplateBuilder::downtimeHeaders()) as $h) {
            $this->assertContains(ExcelValue::header($h), $known, "Kolom \"$h\" tidak dikenali parser");
        }
        // semua jenis reject & downtime di katalog ada kolomnya di template
        $resultNorm = array_map([ExcelValue::class, 'header'], TemplateBuilder::resultHeaders());
        foreach (array_keys(config('production.defects')) as $key) {
            $this->assertContains($key, $resultNorm, "Jenis reject \"$key\" belum ada di template");
        }
        $dtNorm = array_map([ExcelValue::class, 'header'], TemplateBuilder::downtimeHeaders());
        foreach (array_keys(config('production.downtimes')) as $key) {
            $this->assertContains($key, $dtNorm, "Downtime \"$key\" belum ada di template");
        }
    }

    public function test_example_template_parses_without_errors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new TemplateBuilder)->build($path, true, new DateTimeImmutable('2026-11-15'));

        $parser = new WorkbookParser;
        $this->assertSame('daily', $parser->detectType($path));
        $rows = $parser->parseDaily($path, ['CUTP-501' => 1, 'CUTL-501' => 2]);
        @unlink($path);

        $this->assertSame([], $parser->issues, json_encode($parser->issues));
        $this->assertCount(3, $rows);
        $r = $rows[0];
        $this->assertSame('2026-11-15', $r['date']);
        $this->assertSame(1, $r['shift']);
        $this->assertSame('07:00:00', $r['start']);
        $this->assertSame(180, $r['working']);
        $this->assertSame(1700, $r['ok']);
        $this->assertSame(['baret' => 6, 'karat' => 4], $r['defects']);
        $this->assertSame(30, array_sum(array_column($r['downtimes'], 0)));
        $this->assertSame([5, 1], $r['downtimes']['p horizontal']);
        $this->assertSame('Bahan datang telat', $rows[1]['downtime_note']);
    }

    public function test_empty_template_has_only_headers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new TemplateBuilder)->build($path, false);
        $parser = new WorkbookParser;
        $this->assertSame('daily', $parser->detectType($path));
        $this->assertSame([], $parser->parseDaily($path, []));
        @unlink($path);
    }
}
