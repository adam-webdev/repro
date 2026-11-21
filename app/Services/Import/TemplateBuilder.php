<?php

namespace App\Services\Import;

use App\Models\Machine;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Template Excel resmi untuk import laporan harian.
 * Nama kolom = nama kolom yang dikenali WorkbookParser (lihat tests/Unit/ImportTemplateTest),
 * jadi file yang mengikuti template ini pasti terbaca.
 */
class TemplateBuilder
{
    /** Kolom kunci: harus sama persis di sheet Hasil dan sheet DT (untuk memasangkan baris). */
    public const KEY_COLUMNS = [
        'Customer', 'Kode Material', 'Area', 'Tanggal', 'Order', 'Kode Mesin', 'Shift',
        'Jam Mulai', 'Jam Selesai', 'Panjang Bahan Baku', 'Jumlah Bahan Baku', 'Panjang Hasil',
    ];

    public const RESULT_COLUMNS = ['Baik', 'NC', 'Keterangan NC', 'Total Cuting Pcs', 'Reject', 'Keterangan Reject'];

    /** Rincian reject (jumlah = kolom Reject). Nama sama dengan file asli. */
    public const DEFECT_COLUMNS = [
        'Baret', 'Seting Awal/Coaxial over(swaging)', 'Champer Miring/Cembung(swaging)', 'Ngecap', 'Scratch',
        'kependekan(Circle)/Lost Champer(Haven)/Tidak Kepotong(Laser)', 'kepanjangan', 'karat', 'Dent Cuting', 'Dent Champer',
        'Dent Smaco', 'Champer Kasar', 'Champer Ngetril', 'inside bead tinggi', 'las-lasan', 'Burry Tinggi/Kasar',
        'penyok/Cacat Remove', 'Bengkok', 'Dent P. Horizontal', 'Pin Tidak Masuk', 'Melintir', 'Penyok LT', 'Champer Nabrak',
        'Cacat LT', 'Tidak Terpotong Awal', 'Tidak Terpotong Akhir', 'Tidak Masuk Jig', 'Penyok Pipa Jatuh', 'Sambungan', 'Melipat',
    ];

    public const TAIL_COLUMNS = ['Total Jam Kerja', 'Baik kg', 'NC kg', 'Reject kg', 'Total Cuting kg'];

    /** Kolom downtime (menit), frekuensi (x), dan keterangan. Nama sama dengan file asli. */
    public const DOWNTIME_COLUMNS = [
        'Seting Awal', 'Ganti Panjang', 'Ganti Tebal', 'Persiapan', 'G_Karpet', 'Seting Cuting Baret', 'Seting Cuting Penyok',
        'Seting Lost Champer', 'Seting Cekam Ngecap', 'Potongan Lolos', 'Seting Cuting Macet', 'Bongkar Cekam Cuting',
        'Seting Champer Miring', 'Pipa_Nyangkut_Conveyor/Penuh(*Circle Tunggu Proses Deburring Inline)', 'Seting_Cropcut',
        'Seting_Brushing', 'Rantai_Brushing_Putus', 'Seting_Pipasuport', 'Seting_Conveyor', 'Seting Cuting Muter', 'G_RumahPahat',
        'Seting Champer Muter', 'Seting_PanjangPendek', 'Seting Pahat Champer', 'Frek Seting Pahat', 'Keterangan Seting Pahat',
        'P Horizontal', 'Frek Horizontal', 'P Vertical', 'Frek Vertical', 'P Plan', 'Frek Plan', 'P Dalam', 'Frek Dalam',
        'P Luar', 'Frek Luar', 'Keterangan Ganti Pahat', 'G_Gergaji', 'F_Gergaji', 'Cek & Hitung Hasil produksi',
        'Ganti Air Collant', 'Tambah Oli Hidrolik', 'Bersihkan Sisa Potongan', 'Lain -Lain', 'Keterangan',
        'Komponen Mekanik/Elektrik Rusak', 'Keterangan Down Time Tinggi', 'Hydrolik Rusak', 'Perbaikan Panel Rusak',
        'Listrik Mati', 'COMPRESSOR TEKANAN KURANG / RUSAK', 'PREVENTIVE', '-', 'Asah Pahat', 'Burning Feed Roll', 'Tunggu MTC',
        'BE', 'Tunggu Kep PPIC', 'Tunggu Kep QC, Verifikasi LT', 'Tunggu Bahan', 'Pindah Box Hasil Produksi',
        'Tunggu Asah Gergaji', 'Tunggu Box/Andang', 'Ket', 'Pindah Ke Mesin Lain', 'Waktu Hilang', 'Meeting', '5R',
    ];

    public const REQUIRED = ['Tanggal', 'Shift', 'Kode Mesin', 'Order', 'Baik'];

    public static function resultHeaders(): array
    {
        return array_merge(self::KEY_COLUMNS, self::RESULT_COLUMNS, self::DEFECT_COLUMNS, self::TAIL_COLUMNS);
    }

    public static function downtimeHeaders(): array
    {
        return array_merge(self::KEY_COLUMNS, self::DOWNTIME_COLUMNS);
    }

    /** Tulis template ke $path. $withExamples = isi 3 baris contoh (untuk uji coba). */
    public function build(string $path, bool $withExamples = false, ?DateTimeImmutable $date = null, ?array $rows = null): void
    {
        $date ??= new DateTimeImmutable('today');
        $writer = new Writer;
        $writer->openToFile($path);

        // $rows: baris sendiri [['key' => [...], 'result' => [...], 'dt' => [...]], ...] (dipakai pengujian)
        $examples = $rows ?? ($withExamples ? $this->examples($date) : []);

        // ------------------------------------------------ Sheet 1: Hasil Produksi
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Hasil Produksi');
        $sheet->setSheetView((new SheetView)->withFreezeRow(2)->withFreezeColumn('G'));
        $this->widths($sheet, self::resultHeaders());
        $writer->addRow($this->headerRow(self::resultHeaders(), 'result'));
        foreach ($examples as $ex) {
            $writer->addRow($this->dataRow(self::resultHeaders(), $ex['key'] + $ex['result']));
        }

        // ------------------------------------------------ Sheet 2: DT
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('DT');
        $sheet->setSheetView((new SheetView)->withFreezeRow(2)->withFreezeColumn('G'));
        $this->widths($sheet, self::downtimeHeaders());
        $writer->addRow($this->headerRow(self::downtimeHeaders(), 'downtime'));
        foreach ($examples as $ex) {
            if (($ex['dt'] ?? []) === false) {
                continue; // baris uji tanpa pasangan DT
            }
            $writer->addRow($this->dataRow(self::downtimeHeaders(), ($ex['dt_key'] ?? $ex['key']) + $ex['dt']));
        }

        // ------------------------------------------------ Sheet 3: Petunjuk
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Petunjuk');
        $sheet->setColumnWidth(4, 1);
        $sheet->setColumnWidth(110, 2);
        $title = (new Style)->withFontBold(true)->withFontSize(14);
        $bold = (new Style)->withFontBold(true);
        $wrap = (new Style)->withShouldWrapText(true)->withCellVerticalAlignment(CellVerticalAlignment::TOP);
        $writer->addRow(Row::fromValuesWithStyle(['', 'Template Import Laporan Harian Produksi'], $title));
        $writer->addRow(Row::fromValues(['', 'Ikuti aturan di bawah supaya file bisa diimport tanpa error.']));
        $writer->addRow(Row::fromValues(['']));
        foreach ($this->rules($withExamples) as [$no, $text]) {
            $writer->addRow(new Row([Cell::fromValue($no, $bold), Cell::fromValue($text, $wrap)]));
        }

        // ------------------------------------------------ Sheet 4: Master Mesin
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Master Mesin');
        $sheet->setColumnWidth(16, 1, 2, 3, 4);
        $writer->addRow($this->headerRow(['Kode Mesin', 'Area', 'Gedung', 'Jenis (form karu)'], 'plain'));
        foreach (Machine::with('area')->where('is_active', true)->orderBy('code')->get() as $m) {
            $writer->addRow(Row::fromValues([$m->code, $m->area?->name, $m->building, config('production.machine_types.'.Machine::formType($m->code).'.label')]));
        }

        // ------------------------------------------------ Sheet 5: Daftar Kolom
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Daftar Kolom');
        $sheet->setColumnWidth(48, 1);
        $sheet->setColumnWidth(16, 2, 3);
        $sheet->setColumnWidth(70, 4);
        $writer->addRow($this->headerRow(['Nama kolom', 'Sheet', 'Wajib?', 'Isi'], 'plain'));
        foreach ($this->columnGuide() as $r) {
            $writer->addRow(Row::fromValues($r));
        }

        $writer->close();
    }

    private function rules(bool $withExamples): array
    {
        $lock = config('production.lock_days');
        $rules = [
            ['1.', 'File berisi 2 sheet data: "Hasil Produksi" (hasil per order) dan "DT" (menit downtime per order). Sheet lain (Petunjuk, Master Mesin, Daftar Kolom) boleh dibiarkan atau dihapus.'],
            ['2.', 'JANGAN mengubah nama kolom di baris 1. Urutan kolom boleh berubah dan kolom tambahan boleh ada (akan diabaikan), tapi namanya harus sama.'],
            ['3.', 'Kolom wajib: Tanggal, Shift, Kode Mesin, Order, Baik (judul berwarna hitam). Baris tanpa kolom wajib atau dengan kode mesin yang tidak ada di sheet "Master Mesin" tidak akan diimport.'],
            ['4.', 'Tanggal diisi format tanggal Excel (mis. 15/11/2026). Shift diisi 1, 2, atau 3. Jam Mulai / Jam Selesai format jam (07:00). Shift malam yang lewat tengah malam tetap pakai tanggal shift dimulai.'],
            ['5.', 'Satu baris = satu order di satu mesin. Jika satu mesin mengerjakan beberapa order dalam satu shift, buat beberapa baris.'],
            ['6.', 'Setiap baris di sheet "Hasil Produksi" HARUS punya pasangan di sheet "DT" dengan isi 12 kolom pertama (Customer s/d Panjang Hasil) yang sama persis. Cara paling aman: salin kolom A–L dari sheet Hasil ke sheet DT.'],
            ['7.', 'Total Cuting Pcs = Baik + NC + Reject (boleh dikosongkan, dihitung otomatis). Jumlah rincian reject (Baret, Ngecap, …) sebaiknya sama dengan kolom Reject.'],
            ['8.', 'Kolom downtime di sheet DT diisi MENIT. Kolom "Frek …" diisi berapa kali (x). Kolom "Keterangan …" boleh teks. Kosong = 0.'],
            ['9.', 'Total Jam Kerja (menit) boleh dikosongkan, akan dihitung dari Jam Mulai – Jam Selesai. Cutting time = Jam kerja − total downtime.'],
            ['10.', 'Satu file boleh berisi 1 shift, 1 hari, atau beberapa hari sekaligus.'],
            ['11.', 'Import ulang tanggal + shift yang sama akan MENGGANTI data lama (tidak dobel). Sebelum disimpan, sistem menampilkan baris mana yang berubah. Data lama tetap tersimpan di riwayat.'],
            ['12.', "Data yang lebih lama dari {$lock} hari hanya bisa diganti oleh Admin Master."],
            ['13.', 'Simpan sebagai .xlsx (Excel Workbook), maksimal 50 MB.'],
        ];
        if ($withExamples) {
            $rules[] = ['!', 'File ini berisi 3 BARIS CONTOH (tanggal hari ini, shift 1) untuk uji coba. Jika diimport, data tanggal & shift tersebut akan dibuat / diganti. Hapus baris contoh sebelum dipakai untuk data asli.'];
        }

        return $rules;
    }

    private function columnGuide(): array
    {
        $info = [
            'Customer' => 'Nama customer (PT. / TBK akan dirapikan otomatis)',
            'Kode Material' => 'Kode material SAP, mis. X1B13AIA0100+08620',
            'Area' => 'Opsional (area diambil dari master mesin)',
            'Tanggal' => 'Tanggal produksi',
            'Order' => 'Nomor order SAP (6 digit atau lebih)',
            'Kode Mesin' => 'Harus ada di sheet Master Mesin, mis. CUTP-501',
            'Shift' => '1, 2, atau 3',
            'Jam Mulai' => 'Jam mulai order, mis. 07:00',
            'Jam Selesai' => 'Jam selesai order, mis. 10:30',
            'Panjang Bahan Baku' => 'mm per batang (GI)',
            'Jumlah Bahan Baku' => 'Jumlah batang bahan baku',
            'Panjang Hasil' => 'mm per potong',
            'Baik' => 'Hasil baik (pcs)',
            'NC' => 'Hasil NC (pcs)',
            'Keterangan NC' => 'Penyebab NC + nomor NCR, mis. "KARAT NCR-215"',
            'Total Cuting Pcs' => 'Baik + NC + Reject (boleh kosong)',
            'Reject' => 'Total reject (pcs)',
            'Keterangan Reject' => 'Teks bebas',
            'Total Jam Kerja' => 'Menit (boleh kosong, dihitung dari jam mulai–selesai)',
        ];
        $rows = [];
        foreach (self::KEY_COLUMNS as $c) {
            $rows[] = [$c, 'Hasil & DT', in_array($c, self::REQUIRED, true) ? 'Wajib' : '', $info[$c] ?? ''];
        }
        foreach (self::RESULT_COLUMNS as $c) {
            $rows[] = [$c, 'Hasil', in_array($c, self::REQUIRED, true) ? 'Wajib' : '', $info[$c] ?? ''];
        }
        foreach (self::DEFECT_COLUMNS as $c) {
            $rows[] = [$c, 'Hasil', '', 'Rincian reject (pcs)'];
        }
        foreach (self::TAIL_COLUMNS as $c) {
            $rows[] = [$c, 'Hasil', '', $info[$c] ?? 'Berat (kg), opsional'];
        }
        foreach (self::DOWNTIME_COLUMNS as $c) {
            $h = ExcelValue::header($c);
            $isFreq = str_starts_with($h, 'frek') || str_starts_with($h, 'f gergaji');
            $isNote = in_array($h, config('production.downtime_notes'), true);
            $def = config('production.downtimes')[$h] ?? null;
            $rows[] = [$c, 'DT', '', $isFreq ? 'Frekuensi (x)' : ($isNote ? 'Keterangan (teks)' : 'Downtime (menit)'.($def ? ' · '.config('production.downtime_categories')[$def[0]] : ''))];
        }

        return $rows;
    }

    /** 3 baris contoh yang lolos validasi. */
    private function examples(DateTimeImmutable $date): array
    {
        $t = fn (string $hm) => new DateTimeImmutable('1899-12-30 '.$hm);
        $key = fn ($cust, $mat, $order, $mc, $start, $end, $rawLen, $rawQty, $resLen) => ['key' => [
            'Customer' => $cust, 'Kode Material' => $mat, 'Area' => null, 'Tanggal' => $date, 'Order' => $order, 'Kode Mesin' => $mc,
            'Shift' => 1, 'Jam Mulai' => $t($start), 'Jam Selesai' => $t($end), 'Panjang Bahan Baku' => $rawLen,
            'Jumlah Bahan Baku' => $rawQty, 'Panjang Hasil' => $resLen,
        ]];

        return [
            $key('RINNAI INDONESIA', 'X1B13AIA0100+08620', '450001', 'CUTP-501', '07:00', '10:00', 5000, 30, 86.2) + [
                'result' => ['Baik' => 1700, 'NC' => 20, 'Keterangan NC' => 'KARAT NCR-215', 'Total Cuting Pcs' => 1730, 'Reject' => 10,
                    'Keterangan Reject' => null, 'Baret' => 6, 'karat' => 4, 'Total Jam Kerja' => 180],
                'dt' => ['Seting Awal' => 10, 'Seting Cuting Baret' => 15, 'P Horizontal' => 5, 'Frek Horizontal' => 1],
            ],
            $key('RINNAI INDONESIA', 'X1B13AIA0100+08620', '450002', 'CUTP-501', '10:00', '15:00', 5000, 50, 86.2) + [
                'result' => ['Baik' => 2850, 'NC' => 0, 'Total Cuting Pcs' => 2858, 'Reject' => 8, 'Ngecap' => 8, 'Total Jam Kerja' => 300],
                'dt' => ['Ganti Panjang' => 15, 'Bersihkan Sisa Potongan' => 10, 'Tunggu Bahan' => 20, 'Keterangan' => 'Bahan datang telat'],
            ],
            $key('KAYABA INDONESIA', 'X1B26CBG0070+02000', '450003', 'CUTL-501', '07:00', '15:00', 6000, 20, 200) + [
                'result' => ['Baik' => 560, 'NC' => 0, 'Total Cuting Pcs' => 563, 'Reject' => 3, 'Cacat LT' => 3, 'Total Jam Kerja' => 480],
                'dt' => ['Seting Awal' => 20, 'Komponen Mekanik/Elektrik Rusak' => 45, 'Keterangan Down Time Tinggi' => 'Loader error, tunggu MTC'],
            ],
        ];
    }

    private function headerRow(array $headers, string $kind): Row
    {
        $required = (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('0B0B0B')->withShouldWrapText(true);
        $key = (new Style)->withFontBold(true)->withBackgroundColor('E1E0D9')->withShouldWrapText(true);
        $defect = (new Style)->withFontBold(true)->withBackgroundColor('FDECEC')->withShouldWrapText(true);
        $dt = (new Style)->withFontBold(true)->withBackgroundColor('FFF6E0')->withShouldWrapText(true);
        $plain = (new Style)->withFontBold(true)->withBackgroundColor('E1E0D9');

        $cells = array_map(function ($h) use ($kind, $required, $key, $defect, $dt, $plain) {
            $style = match (true) {
                $kind === 'plain' => $plain,
                in_array($h, self::REQUIRED, true) => $required,
                in_array($h, self::DEFECT_COLUMNS, true) => $defect,
                $kind === 'downtime' && in_array($h, self::DOWNTIME_COLUMNS, true) => $dt,
                default => $key,
            };

            return Cell::fromValue($h, $style);
        }, $headers);

        return new Row($cells, $kind === 'plain' ? Row::DEFAULT_HEIGHT : 45);
    }

    private function dataRow(array $headers, array $values): Row
    {
        $date = (new Style)->withFormat('dd/mm/yyyy');
        $time = (new Style)->withFormat('hh:mm');

        return new Row(array_map(function ($h) use ($values, $date, $time) {
            $v = $values[$h] ?? null;
            if ($h === 'Tanggal' && $v) {
                return Cell::fromValue($v, $date);
            }
            if (($h === 'Jam Mulai' || $h === 'Jam Selesai') && $v) {
                return Cell::fromValue($v, $time);
            }

            return Cell::fromValue($v);
        }, $headers));
    }

    private function widths($sheet, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $w = match ($h) {
                'Customer' => 26, 'Kode Material' => 22, 'Keterangan NC', 'Keterangan Reject', 'Keterangan', 'Keterangan Down Time Tinggi',
                'Keterangan Seting Pahat', 'Keterangan Ganti Pahat', 'Ket' => 24,
                'Tanggal' => 12, 'Shift', 'Area' => 8, 'Kode Mesin' => 12,
                default => max(9, min(16, (int) ceil(mb_strlen($h) / 2) + 2)),
            };
            $sheet->setColumnWidth($w, $i + 1);
        }
    }
}
