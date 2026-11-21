<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportService;
use App\Services\Import\TemplateBuilder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Tests\TestCase;

/**
 * Uji skenario import end-to-end di MySQL (database report_system_test):
 * baru, import ulang, revisi (ubah / tambah / hilang), dobel, klik ganda, 2 admin bersamaan,
 * kunci data lama, hapus, SAP. File dibuat dari template resmi (lengkap dengan sheet Petunjuk dll).
 */
class ImportScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $master;

    private User $produksi;

    private string $d1;   // kemarin

    private string $old;  // 20 hari lalu (terkunci untuk Admin Produksi)

    protected function beforeRefreshingDatabase()
    {
        config(['database.connections.mysql_test' => array_merge(config('database.connections.mysql'), ['database' => 'report_system_test'])]);
        try {
            DB::connection('mysql_test')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL report_system_test tidak tersedia: '.$e->getMessage());
        }
        config(['database.default' => 'mysql_test']);
        DB::purge('mysql_test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = User::where('username', 'admin')->firstOrFail();
        $emp = Employee::create(['name' => 'Admin Produksi Uji', 'position' => 'Admin Produksi']);
        $this->produksi = User::create(['name' => $emp->name, 'username' => 'produksi.uji', 'password' => 'rahasia123', 'role' => 'produksi', 'employee_id' => $emp->id]);
        $this->d1 = now()->subDay()->toDateString();
        $this->old = now()->subDays(20)->toDateString();
    }

    // ------------------------------------------------------------------ helper

    private function row(string $date, int $shift, string $machine, string $order, string $start, string $end, int $ok, array $opt = []): array
    {
        $t = fn ($hm) => new DateTimeImmutable('1899-12-30 '.$hm);

        return [
            'key' => [
                'Customer' => $opt['customer'] ?? 'PT. RINNAI INDONESIA', 'Kode Material' => 'X1B13AIA0100+08620', 'Tanggal' => new DateTimeImmutable($date),
                'Order' => $order, 'Kode Mesin' => $machine, 'Shift' => $shift, 'Jam Mulai' => $t($start), 'Jam Selesai' => $t($end),
                'Panjang Bahan Baku' => 5000, 'Jumlah Bahan Baku' => 30, 'Panjang Hasil' => 86.2,
            ],
            'result' => ['Baik' => $ok, 'NC' => $opt['nc'] ?? 0, 'Reject' => $opt['reject'] ?? 0] + ($opt['defects'] ?? []),
            'dt' => $opt['dt'] ?? ['Seting Awal' => 10],
        ];
    }

    private function preview(array $rows, User $user, string $name = 'uji.xlsx'): ImportBatch
    {
        $rel = 'imports/test_'.uniqid().'.xlsx';
        @mkdir(storage_path('app/private/imports'), 0775, true);
        (new TemplateBuilder)->build(storage_path('app/private/'.$rel), false, null, $rows);
        $batch = ImportBatch::create(['type' => 'daily', 'user_id' => $user->id, 'file_name' => $name, 'file_path' => $rel, 'status' => 'preview']);

        return app(ImportService::class)->preview($batch)->fresh();
    }

    private function import(array $rows, ?User $user = null): ImportBatch
    {
        $user ??= $this->produksi;

        return app(ImportService::class)->commit($this->preview($rows, $user), $user)->fresh();
    }

    private function active(string $date, ?int $shift = null)
    {
        return DB::table('production_reports')->where('production_date', $date)->when($shift, fn ($q) => $q->where('shift', $shift))->whereNull('deleted_at');
    }

    private function summaryOk(string $date, ?int $shift = null): int
    {
        return (int) DB::table('daily_machine_summaries')->where('production_date', $date)->when($shift, fn ($q) => $q->where('shift', $shift))->sum('ok_qty');
    }

    private function fileA(): array
    {
        return [
            $this->row($this->d1, 1, 'CUTP-501', '450001', '07:00', '10:00', 1700, ['reject' => 10, 'defects' => ['Baret' => 6, 'karat' => 4]]),
            $this->row($this->d1, 1, 'CUTP-501', '450002', '10:00', '15:00', 2850),
            $this->row($this->d1, 1, 'CUTL-501', '450003', '07:00', '15:00', 560, ['dt' => ['Seting Awal' => 20, 'Komponen Mekanik/Elektrik Rusak' => 45]]),
            $this->row($this->d1, 2, 'CUTP-501', '450002', '15:00', '19:00', 2100),
            $this->row($this->d1, 2, 'CUTC-503', '450004', '15:00', '23:00', 3000),
        ];
    }

    // ------------------------------------------------------------------ skenario

    public function test_new_import_creates_rows_summary_orders_and_log(): void
    {
        $batch = $this->preview($this->fileA(), $this->produksi);
        $this->assertSame('daily', $batch->type);
        $this->assertSame(5, $batch->rows_valid);
        $this->assertSame(0, $batch->errors()->count(), 'Sheet Petunjuk / Master Mesin / Daftar Kolom tidak boleh ikut terbaca');

        app(ImportService::class)->commit($batch, $this->produksi);

        $this->assertSame(3, $this->active($this->d1, 1)->count());
        $this->assertSame(2, $this->active($this->d1, 2)->count());
        $this->assertSame(1700 + 2850 + 560, $this->summaryOk($this->d1, 1));
        $this->assertSame(65, (int) $this->active($this->d1, 1)->where('order_no', '450003')->value('downtime_minutes'));
        $this->assertSame(2, (int) DB::table('production_orders')->where('order_no', '450002')->value('report_count'));
        $this->assertSame(2, DB::table('production_defects')->count());
        $this->assertSame($this->produksi->id, (int) $this->active($this->d1, 1)->value('created_by'));
        $this->assertSame(2, ActivityLog::where('subject_type', 'production_data')->where('action', 'create')->count());
    }

    public function test_reimporting_identical_file_never_duplicates(): void
    {
        $this->import($this->fileA());
        $firstCreated = $this->active($this->d1, 1)->min('created_at');

        $again = $this->preview($this->fileA(), $this->produksi);
        foreach ($again->groups as $g) {
            $this->assertSame($g['rows'], $g['diff']['same'], 'Isi identik harus terbaca "sama"');
            $this->assertSame(0, $g['diff']['changed_count'] + $g['diff']['added_count'] + $g['diff']['removed']);
        }
        app(ImportService::class)->commit($again, $this->produksi);
        $this->import($this->fileA()); // ketiga kali

        $this->assertSame(5, $this->active($this->d1)->count(), 'Tidak boleh dobel');
        $this->assertSame(1700 + 2850 + 560, $this->summaryOk($this->d1, 1));
        $this->assertSame(2, (int) DB::table('production_orders')->where('order_no', '450002')->value('report_count'), 'Akumulasi order tidak boleh terhitung dua kali');
        $this->assertSame(2, DB::table('production_defects')->join('production_reports as r', 'r.id', '=', 'production_report_id')->whereNull('r.deleted_at')->count());
        $this->assertSame((string) $firstCreated, (string) $this->active($this->d1, 1)->min('created_at'), 'Waktu input pertama tetap');
        $this->assertSame(10, DB::table('production_reports')->whereNotNull('deleted_at')->count(), 'Versi lama tersimpan sebagai riwayat');
    }

    public function test_revision_updates_adds_and_removes_rows_only_in_that_shift(): void
    {
        $this->import($this->fileA());
        $shift2Ids = $this->active($this->d1, 2)->orderBy('id')->pluck('id')->all();

        $revisi = [
            $this->row($this->d1, 1, 'CUTP-501', '450001', '07:00', '10:00', 1650, ['reject' => 10, 'defects' => ['Baret' => 6, 'karat' => 4]]), // berubah
            // 450002 dihapus dari file
            $this->row($this->d1, 1, 'CUTL-501', '450003', '07:00', '15:00', 560, ['dt' => ['Seting Awal' => 20, 'Komponen Mekanik/Elektrik Rusak' => 45]]), // sama
            $this->row($this->d1, 1, 'CUTL-502', '450010', '07:00', '12:00', 900), // baru
        ];
        $batch = $this->preview($revisi, $this->produksi);
        $this->assertCount(1, $batch->groups, 'File hanya berisi shift 1');
        $diff = $batch->groups[0]['diff'];
        $this->assertSame(1, $diff['same']);
        $this->assertSame([['Baik', '1700', '1650']], $diff['changed'][0]['fields']);
        $this->assertSame('450010', $diff['added'][0]['order']);
        $this->assertSame(1, $diff['removed']);
        $this->assertSame('450002', $diff['removed_rows'][0]['order']);

        app(ImportService::class)->commit($batch, $this->master);

        $this->assertSame(['450001', '450003', '450010'], $this->active($this->d1, 1)->orderBy('order_no')->pluck('order_no')->all());
        $this->assertSame(1650 + 560 + 900, $this->summaryOk($this->d1, 1));
        $this->assertSame($shift2Ids, $this->active($this->d1, 2)->orderBy('id')->pluck('id')->all(), 'Shift 2 tidak boleh tersentuh');
        $this->assertSame(1, (int) DB::table('production_orders')->where('order_no', '450002')->value('report_count'), 'Order yang hilang dari shift 1 tinggal 1 laporan (shift 2)');
        $this->assertSame($this->produksi->id, (int) $this->active($this->d1, 1)->value('created_by'), 'Penginput pertama tetap');
        $this->assertSame($this->master->id, (int) $this->active($this->d1, 1)->value('updated_by'), 'Revisi oleh master tercatat');
    }

    public function test_duplicate_rows_inside_one_file_are_rejected(): void
    {
        $rows = $this->fileA();
        $rows[] = $rows[0]; // salin-tempel baris pertama
        $batch = $this->preview($rows, $this->produksi);

        $this->assertSame(5, $batch->rows_valid);
        $this->assertSame(1, $batch->errors()->where('level', 'error')->where('message', 'like', 'Baris dobel%')->count());
        app(ImportService::class)->commit($batch, $this->produksi);
        $this->assertSame(1, $this->active($this->d1, 1)->where('order_no', '450001')->count());
    }

    public function test_same_order_same_hours_with_different_raw_batch_is_not_a_duplicate(): void
    {
        // Pola nyata di data historis: 1 order, mesin & jam sama, tapi 2 batch bahan baku (panjang / jumlah berbeda).
        $a = $this->row($this->d1, 1, 'CUTC-521', '409274', '07:00', '18:00', 1530, ['dt' => ['Seting Awal' => 60]]);
        $b = $this->row($this->d1, 1, 'CUTC-521', '409274', '07:00', '18:00', 96, ['dt' => []]);
        $b['key']['Jumlah Bahan Baku'] = 8;
        $batch = $this->preview([$a, $b], $this->produksi);

        $this->assertSame(2, $batch->rows_valid);
        $this->assertSame(0, $batch->errors()->count(), json_encode($batch->errors()->pluck('message')));
        app(ImportService::class)->commit($batch, $this->produksi);
        $this->assertSame(2, $this->active($this->d1, 1)->count());
        $this->assertSame(60, (int) $this->active($this->d1, 1)->where('ok_qty', 1530)->value('downtime_minutes'), 'DT terpasang ke baris yang benar');

        // import ulang file yang sama -> tetap 2 baris, terbaca "sama"
        $again = $this->preview([$a, $b], $this->produksi);
        $this->assertSame(2, $again->groups[0]['diff']['same']);
        app(ImportService::class)->commit($again, $this->produksi);
        $this->assertSame(2, $this->active($this->d1, 1)->count());
    }

    public function test_double_click_commit_is_blocked(): void
    {
        $batch = $this->preview($this->fileA(), $this->produksi);
        app(ImportService::class)->commit($batch, $this->produksi);

        try {
            app(ImportService::class)->commit($batch, $this->produksi); // objek lama, status masih "preview" di memori
            $this->fail('Commit kedua seharusnya ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah diproses', $e->getMessage());
        }
        $this->assertSame(5, $this->active($this->d1)->count());
    }

    public function test_two_admins_previewing_same_shift_second_must_reupload(): void
    {
        $a = $this->preview($this->fileA(), $this->produksi);
        $b = $this->preview($this->fileA(), $this->master);
        app(ImportService::class)->commit($a, $this->produksi);

        try {
            app(ImportService::class)->commit($b, $this->master);
            $this->fail('Preview yang sudah basi harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah berubah sejak preview', $e->getMessage());
        }
        $this->assertSame(5, $this->active($this->d1)->count());

        // upload ulang -> boleh
        $this->import($this->fileA(), $this->master);
        $this->assertSame(5, $this->active($this->d1)->count());
    }

    public function test_old_data_is_locked_for_admin_produksi_but_back_date_new_data_is_allowed(): void
    {
        // back date data baru (belum ada) -> boleh oleh Admin Produksi
        $this->import([$this->row($this->old, 1, 'CUTP-501', '440001', '07:00', '15:00', 1000)]);
        $this->assertSame(1, $this->active($this->old, 1)->count());

        // mengganti data lama -> ditolak untuk Admin Produksi
        try {
            $this->import([$this->row($this->old, 1, 'CUTP-501', '440001', '07:00', '15:00', 900)]);
            $this->fail('Seharusnya terkunci');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hanya Admin Master', $e->getMessage());
        }
        $this->assertSame(1000, (int) $this->active($this->old, 1)->value('ok_qty'));

        // Admin Master boleh
        $this->import([$this->row($this->old, 1, 'CUTP-501', '440001', '07:00', '15:00', 900)], $this->master);
        $this->assertSame(900, (int) $this->active($this->old, 1)->value('ok_qty'));
        $this->assertSame(1, $this->active($this->old, 1)->count());
    }

    public function test_delete_group_then_reimport(): void
    {
        $this->import($this->fileA());
        $svc = app(ImportService::class);

        $n = $svc->deleteGroup($this->d1, 1, $this->produksi, 'Salah tanggal, uji hapus');
        $this->assertSame(3, $n);
        $this->assertSame(0, $this->active($this->d1, 1)->count());
        $this->assertSame(2, $this->active($this->d1, 2)->count(), 'Shift lain tetap');
        $this->assertSame(0, $this->summaryOk($this->d1, 1), 'Ringkasan dashboard ikut hilang');
        $this->assertSame(1, (int) DB::table('production_orders')->where('order_no', '450002')->value('report_count'));
        $this->assertSame(0, (int) DB::table('production_orders')->where('order_no', '450001')->value('report_count'));
        $this->assertSame(3, DB::table('production_reports')->where('deleted_by', $this->produksi->id)->count(), 'Data terhapus tetap tersimpan');
        $log = ActivityLog::where('action', 'delete')->where('subject_key', $this->d1.'|1')->firstOrFail();
        $this->assertSame('Salah tanggal, uji hapus', $log->summary()['Alasan']);

        try {
            $svc->deleteGroup($this->d1, 1, $this->produksi, 'hapus lagi');
            $this->fail('Tidak ada data yang bisa dihapus lagi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tidak ada data', $e->getMessage());
        }

        // import ulang setelah dihapus -> masuk lagi, tidak dobel
        $this->import($this->fileA(), $this->master);
        $this->assertSame(3, $this->active($this->d1, 1)->count());
        $this->assertSame(2, $this->active($this->d1, 2)->count());
        $this->assertSame(2, (int) DB::table('production_orders')->where('order_no', '450002')->value('report_count'));
    }

    public function test_old_data_delete_needs_master(): void
    {
        $this->import([$this->row($this->old, 2, 'CUTP-501', '440002', '15:00', '23:00', 800)], $this->master);
        try {
            app(ImportService::class)->deleteGroup($this->old, 2, $this->produksi, 'uji kunci');
            $this->fail('Seharusnya terkunci');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Admin Master', $e->getMessage());
        }
        $this->assertSame(1, app(ImportService::class)->deleteGroup($this->old, 2, $this->master, 'uji kunci master'));
    }

    public function test_invalid_rows_are_excluded_and_unpaired_rows_warned(): void
    {
        $rows = $this->fileA();
        $rows[] = $this->row($this->d1, 1, 'XXX-999', '450020', '07:00', '08:00', 10);            // mesin tidak terdaftar
        $rows[] = $this->row($this->d1, 4, 'CUTP-501', '450021', '07:00', '08:00', 10);           // shift 4
        $rows[] = $this->row($this->d1, 1, 'CUTP-503', '450022', '07:00', '08:00', 10, ['dt' => false]); // tanpa DT
        $batch = $this->preview($rows, $this->produksi);

        $this->assertSame(6, $batch->rows_valid);
        $this->assertSame(2, $batch->errors()->where('level', 'error')->count());
        $this->assertSame(1, $batch->errors()->where('message', 'like', 'Tidak ada pasangan%')->count());
        app(ImportService::class)->commit($batch, $this->produksi);
        $this->assertSame(4, $this->active($this->d1, 1)->count());
        $this->assertSame(0, (int) $this->active($this->d1, 1)->where('order_no', '450022')->value('downtime_minutes'));
    }

    public function test_night_shift_across_midnight(): void
    {
        $this->import([$this->row($this->d1, 3, 'CUTP-501', '450030', '23:00', '07:00', 1200, ['dt' => ['Seting Awal' => 30]])]);
        $r = $this->active($this->d1, 3)->first();
        $this->assertSame(480, (int) $r->working_minutes);
        $this->assertSame(450, (int) $r->cutting_minutes);
    }

    public function test_sap_reimport_updates_without_duplicates(): void
    {
        $this->import($this->fileA());
        $make = function (float $gr) {
            $rel = 'imports/sap_'.uniqid().'.xlsx';
            $w = new Writer;
            $w->openToFile(storage_path('app/private/'.$rel));
            $w->addRow(Row::fromValues(['500000450001', 120.5, $gr]));
            $w->addRow(Row::fromValues(['500000450002', 300.0, 97.1]));
            $w->close();

            return ImportBatch::create(['type' => 'daily', 'user_id' => $this->produksi->id, 'file_name' => 'sap.xlsx', 'file_path' => $rel, 'status' => 'preview']);
        };
        $svc = app(ImportService::class);
        $svc->commit($svc->preview($make(95.0))->fresh(), $this->produksi);
        $svc->commit($svc->preview($make(96.5))->fresh(), $this->produksi);

        $this->assertSame(2, DB::table('sap_order_results')->count());
        $this->assertSame(96.5, (float) DB::table('sap_order_results')->where('order_no', '450001')->value('gr_percent'));
    }

    public function test_web_routes_for_template_and_delete(): void
    {
        $this->import($this->fileA());
        $this->actingAs($this->produksi)->get('/imports/template')->assertOk()->assertDownload('template_import_produksi.xlsx');

        $viewer = User::create(['name' => 'Viewer', 'username' => 'viewer.uji', 'password' => 'rahasia123', 'role' => 'viewer']);
        $this->actingAs($viewer)->post('/imports/delete-group', ['date' => $this->d1, 'shift' => 1, 'reason' => 'tidak boleh'])->assertForbidden();

        $this->actingAs($this->produksi)->post('/imports/delete-group', ['date' => $this->d1, 'shift' => 1])->assertSessionHasErrors('reason');
        $this->actingAs($this->produksi)->post('/imports/delete-group', ['date' => $this->d1, 'shift' => 1, 'reason' => 'salah input'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, $this->active($this->d1, 1)->count());

        $this->actingAs($this->produksi)->get('/reports?from='.$this->d1.'&to='.$this->d1.'&shift=2')->assertOk()->assertSee('Hapus data tanggal & shift ini', false);
        $this->actingAs($this->produksi)->get('/?from='.$this->d1.'&to='.$this->d1)->assertOk();
        $this->actingAs($this->produksi)->getJson('/dashboard/data?from='.$this->d1.'&to='.$this->d1)->assertOk()->assertJsonPath('kpi.ok', 2100 + 3000);
    }
}
