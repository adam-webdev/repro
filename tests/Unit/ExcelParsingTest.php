<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Material;
use App\Services\Import\ExcelValue;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ExcelParsingTest extends TestCase
{
    public function test_header_normalization_matches_excel_variants(): void
    {
        $this->assertSame('tunggu kep qc verifikasi lt', ExcelValue::header('Tunggu Kep QC, Verifikasi LT'));
        $this->assertSame('seting panjangpendek', ExcelValue::header('Seting_PanjangPendek'));
        $this->assertSame('5r', ExcelValue::header('5R '));
        $this->assertSame('-', ExcelValue::header('-'));
    }

    public function test_dates_and_times(): void
    {
        $this->assertSame('2026-11-15', ExcelValue::date(new DateTimeImmutable('2026-11-15')));
        $this->assertSame('2026-11-15', ExcelValue::date('15/11/2026'));
        $this->assertSame('2026-01-02', ExcelValue::date(46024));
        $this->assertSame('07:00:00', ExcelValue::time(new DateTimeImmutable('1899-12-30 07:00')));
        $this->assertSame('22:30:00', ExcelValue::time('22.30'));
        $this->assertSame(780, ExcelValue::minutesBetween('18:00:00', '07:00:00')); // lewat tengah malam
    }

    public function test_values_and_formula_cells(): void
    {
        $this->assertNull(ExcelValue::str('=VLOOKUP(F5,[1]Sheet4!$A$4:$C$55,3,FALSE)'));
        $this->assertNull(ExcelValue::str('#N/A'));
        $this->assertSame(1234.5, ExcelValue::num('1.234,5'));
        $this->assertSame('408894', ExcelValue::str(408894.0));
    }

    public function test_customer_name_is_normalized(): void
    {
        $this->assertSame('RINNAI INDONESIA', Customer::normalizeName(' PT. RINNAI INDONESIA'));
        $this->assertSame('DHARMA POLIMETAL', Customer::normalizeName('DHARMA POLIMETAL TBK.'));
        $this->assertNull(Customer::normalizeName('#N/A'));
    }

    public function test_material_code_is_parsed(): void
    {
        $this->assertEquals(['thickness_mm' => 1.3, 'od_mm' => 10, 'length_mm' => 862], Material::parseCode('X1B13AIA0100+08620'));
        $this->assertSame([], Material::parseCode('#N/A'));
    }
}
