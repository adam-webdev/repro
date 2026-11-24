<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EvaluationForm;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\ProductionForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AccessAndFormsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, array $attrs = []): User
    {
        $emp = Employee::create(['name' => ucfirst($role).' Test', 'code' => strtoupper(substr($role, 0, 2)).' '.random_int(100, 999), 'position' => 'Jabatan '.$role]);

        return User::create($attrs + [
            'name' => $emp->name, 'username' => $role.random_int(1000, 9999), 'password' => 'rahasia123',
            'role' => $role, 'employee_id' => $emp->id, 'is_active' => true,
        ]);
    }

    public function test_login_with_username_and_forced_password_change(): void
    {
        $u = $this->makeUser('karu', ['username' => 'KA044', 'must_change_password' => true]);

        $this->post('/login', ['login' => 'KA044', 'password' => 'rahasia123'])->assertRedirect(route('profile.edit'));
        $this->get('/forms/produksi')->assertRedirect(route('profile.edit'));

        $this->put('/profile/password', ['current_password' => 'rahasia123', 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])
            ->assertRedirect(route('forms.production.index'));
        $this->assertFalse($u->fresh()->must_change_password);
        $this->get('/forms/produksi')->assertOk();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser('viewer', ['username' => 'nonaktif', 'is_active' => false]);
        $this->post('/login', ['login' => 'nonaktif', 'password' => 'rahasia123'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_menus_follow_role(): void
    {
        $viewer = $this->makeUser('viewer');
        $this->actingAs($viewer)->get('/imports')->assertForbidden();
        $this->actingAs($viewer)->get('/forms/produksi')->assertForbidden();
        $this->actingAs($viewer)->get('/employees')->assertForbidden();

        $produksi = $this->makeUser('produksi');
        $this->actingAs($produksi)->get('/forms/produksi')->assertOk();          // boleh melihat
        $this->actingAs($produksi)->get('/forms/produksi/create')->assertForbidden(); // tidak boleh mengisi
        $this->actingAs($produksi)->get('/employees')->assertForbidden();

        $karu = $this->makeUser('karu');
        $this->actingAs($karu)->get('/imports')->assertForbidden();
        $this->actingAs($karu)->get('/forms/evaluasi/create')->assertOk();

        $master = $this->makeUser('master');
        $this->actingAs($master)->get('/employees')->assertOk();
        $this->actingAs($master)->get('/activity')->assertOk();

        // Monitoring Karu: halaman sendiri di menu Form karu, bukan di dashboard
        $this->actingAs($viewer)->get('/forms/monitoring')->assertForbidden();
        $this->actingAs($karu)->get('/forms/monitoring')->assertForbidden();
        $this->actingAs($produksi)->get('/forms/monitoring')->assertOk()->assertSee('Total produksi pcs')->assertDontSee('vs data admin');
        $this->actingAs($master)->getJson('/forms/monitoring/data')->assertOk()->assertJsonStructure(['kpi', 'kpi_change', 'month' => ['days', 'total_pcs'], 'machines', 'ng_top']);

        $dash = $this->actingAs($viewer)->getJson('/dashboard/data')->assertOk()->json();
        $this->assertArrayNotHasKey('karu', $dash);
        $this->assertArrayNotHasKey('scatter', $dash);
        $this->actingAs($viewer)->get('/')->assertOk()->assertDontSee('Kontrol Karu')->assertDontSee('data janggal');
    }

    public function test_karu_production_form_is_saved_edited_deleted_with_audit_trail(): void
    {
        $karu = $this->makeUser('karu');
        $laser = Machine::create(['code' => 'CUTL-501', 'type' => 'CUTL']);
        $setup = FormOption::create(['group' => 'setup', 'label' => 'Setting Awal']);
        $bongkar = FormOption::create(['group' => 'bongkar', 'label' => 'Bongkar Pasang Cekam']);
        $dtLaser = FormOption::create(['group' => 'dt', 'machine_type' => 'laser', 'label' => 'Ganti Gas']);
        $dtExs = FormOption::create(['group' => 'dt', 'machine_type' => 'exs', 'label' => 'Pembersihan Dies']);

        $payload = fn (array $item = []) => [
            'form_date' => now()->subDays(3)->toDateString(), // back date
            'shift' => 2,
            'items' => [array_merge([
                'machine_type' => 'laser', 'machine_id' => $laser->id, 'order_no' => '408894', 'dimension' => 'Ø10 × 1,3 × 862',
                'working_minutes' => 420, 'cutting_minutes' => 360, 'std_output' => 200, 'act_output' => 170, 'ok_qty' => 1000,
                'problems' => [
                    ['kind' => 'setup', 'option_id' => $setup->id, 'minutes' => 20],
                    ['kind' => 'bongkar', 'option_id' => $bongkar->id, 'minutes' => 10],
                    ['kind' => 'dt', 'option_id' => $dtLaser->id, 'minutes' => 25],
                    ['kind' => 'dt', 'option_id' => null, 'other_text' => 'Tunggu forklift', 'minutes' => 15],
                ],
            ], $item)],
        ];

        // opsi DT khusus EXS tidak boleh dipakai di mesin laser
        $bad = $payload(['problems' => [['kind' => 'dt', 'option_id' => $dtExs->id, 'minutes' => 5]]]);
        $this->actingAs($karu)->postJson('/forms/produksi', $bad)->assertStatus(422)->assertJsonValidationErrors('items.0.problems.0.option_id');

        // opsi Set Up tidak boleh dipakai untuk Bongkar
        $bad = $payload(['problems' => [['kind' => 'bongkar', 'option_id' => $setup->id, 'minutes' => 5]]]);
        $this->actingAs($karu)->postJson('/forms/produksi', $bad)->assertStatus(422)->assertJsonValidationErrors('items.0.problems.0.option_id');

        // karu hanya 2 shift (07–18 & 18–07)
        $this->actingAs($karu)->postJson('/forms/produksi', ['shift' => 3] + $payload())->assertStatus(422)->assertJsonValidationErrors('shift');

        // kolom wajib (*); hasil 2nd grade, NC & keterangan boleh kosong (tidak dikirim di payload)
        $this->actingAs($karu)->postJson('/forms/produksi', $payload(['order_no' => null, 'dimension' => null, 'std_output' => null, 'ok_qty' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.order_no', 'items.0.dimension', 'items.0.std_output', 'items.0.ok_qty'])
            ->assertJsonMissingValidationErrors(['items.0.second_grade_qty', 'items.0.nc_qty', 'items.0.note']);
        $noMinutes = $payload(['problems' => [['kind' => 'setup', 'option_id' => $setup->id, 'minutes' => null]]]);
        $this->actingAs($karu)->postJson('/forms/produksi', $noMinutes)->assertStatus(422)->assertJsonValidationErrors('items.0.problems.0.minutes');

        // "Lainnya" tanpa teks ditolak
        $bad = $payload(['problems' => [['kind' => 'dt', 'option_id' => null, 'other_text' => '', 'minutes' => 5]]]);
        $this->actingAs($karu)->postJson('/forms/produksi', $bad)->assertStatus(422);

        $this->actingAs($karu)->postJson('/forms/produksi', $payload())->assertOk()->assertJsonStructure(['redirect']);
        $form = ProductionForm::firstOrFail();
        $this->assertSame($karu->id, $form->user_id);
        $this->assertSame($karu->employee_id, $form->employee_id);
        $this->assertSame(4, $form->items->first()->problems()->count());
        $this->assertSame('408894', $form->items->first()->order_no);
        $this->assertDatabaseHas('production_form_problems', ['kind' => 'bongkar', 'form_option_id' => $bongkar->id, 'minutes' => 10]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'production_form', 'subject_id' => $form->id, 'action' => 'create', 'user_id' => $karu->id]);

        // edit: hasil OK berubah -> tercatat sebelum & sesudah
        $this->actingAs($karu)->putJson("/forms/produksi/{$form->id}", $payload(['ok_qty' => 950]))->assertOk();
        $log = ActivityLog::where('subject_id', $form->id)->where('action', 'update')->firstOrFail();
        $this->assertSame(1000, $log->changes['old']['Mesin #1 OK']);
        $this->assertSame(950, $log->changes['new']['Mesin #1 OK']);
        $this->assertSame('Jabatan karu', $log->user_position);
        $this->assertContains(['Mesin #1 OK', '1000', '950'], $log->changeRows());
        // karu: tabel & detail form miliknya + tombol Edit, tanpa nama / riwayat input
        $this->actingAs($karu)->get('/forms/produksi')->assertOk()
            ->assertSee(route('forms.production.edit', $form))->assertSee('CUTL-501 · 408894')->assertDontSee('Terakhir diedit');
        $this->actingAs($karu)->get("/forms/produksi/{$form->id}")->assertOk()->assertDontSee('Lihat 1 perubahan')->assertDontSee('Terakhir diubah');

        // admin produksi: melihat nama karu, siapa & kapan input / edit, tapi tidak bisa mengubah
        $admin = $this->makeUser('produksi');
        $this->actingAs($admin)->get('/forms/produksi')->assertOk()
            ->assertSee($karu->name)->assertSee('Terakhir diedit')->assertDontSee(route('forms.production.edit', $form));
        $this->actingAs($admin)->get("/forms/produksi/{$form->id}")->assertOk()->assertSee('Terakhir diubah')->assertSee('Lihat 1 perubahan');
        $this->actingAs($admin)->get("/forms/produksi/{$form->id}/edit")->assertForbidden();

        // karu lain tidak boleh melihat / mengubah / melihat di tabel
        $other = $this->makeUser('karu');
        $this->actingAs($other)->get('/forms/produksi')->assertOk()->assertDontSee('408894');
        $this->actingAs($other)->get("/forms/produksi/{$form->id}")->assertForbidden();
        $this->actingAs($other)->putJson("/forms/produksi/{$form->id}", $payload())->assertForbidden();

        // hapus = soft delete, tetap bisa ditelusuri
        $this->actingAs($karu)->delete("/forms/produksi/{$form->id}")->assertRedirect();
        $this->assertSoftDeleted('production_forms', ['id' => $form->id, 'deleted_by' => $karu->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $form->id, 'action' => 'delete']);
        $this->assertDatabaseCount('production_form_items', 1);
    }

    public function test_evaluation_form_with_multiple_checks(): void
    {
        $karu = $this->makeUser('karu');
        $m = Machine::create(['code' => 'CUTP-501', 'type' => 'CUTP']);
        $visualNg = FormOption::create(['group' => 'visual', 'label' => 'NG']);
        $apd = FormOption::create(['group' => 'compliance', 'label' => 'Penggunaan APD', 'sort' => 1]);
        $fiveR = FormOption::create(['group' => 'compliance', 'label' => '5R', 'sort' => 2]);
        $jigIn = FormOption::create(['group' => 'jig', 'label' => 'JIG In']);
        $photo = fn () => DB::table('attachments')->insertGetId(['user_id' => $karu->id, 'path' => 'x.jpg', 'created_at' => now(), 'updated_at' => now()]);

        // Form Evaluasi: semua kolom wajib diisi
        $full = fn (array $c = []) => array_merge([
            'check_time' => '08:15', 'machine_id' => $m->id, 'customer' => 'PT. RINNAI INDONESIA', 'dimension' => 'Ø10 × 1,3 × 862',
            'planned_start' => '07:30', 'planned_qty' => 1200, 'd1' => 10.02, 'd2' => 10.01, 'd3' => 10, 'd4' => 10.02,
            'thickness_mm' => 1.3, 'ib_height_mm' => 0.2, 'length_mm' => 862, 'jig_option_id' => $jigIn->id, 'visual_option_id' => $visualNg->id,
            'compliance_option_ids' => [$apd->id, $fiveR->id], 'actual_cutting' => 300, 'problem' => 'Tidak ada', 'attachments' => [$photo()],
        ], $c);
        $this->actingAs($karu)->postJson('/forms/evaluasi', [
            'form_date' => now()->toDateString(), 'shift' => 1,
            'checks' => [['machine_id' => $m->id]],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'checks.0.check_time', 'checks.0.customer', 'checks.0.dimension', 'checks.0.planned_start', 'checks.0.planned_qty', 'checks.0.d1',
            'checks.0.length_mm', 'checks.0.actual_cutting', 'checks.0.problem', 'checks.0.attachments',
        ]);
        $this->actingAs($karu)->postJson('/forms/evaluasi', [
            'form_date' => now()->toDateString(), 'shift' => 1,
            'checks' => [$full(['jig_option_id' => null, 'visual_option_id' => null, 'compliance_option_ids' => []])],
        ])->assertStatus(422)->assertJsonValidationErrors(['checks.0.jig_option_id', 'checks.0.visual_option_id', 'checks.0.compliance_option_ids']);

        $this->actingAs($karu)->postJson('/forms/evaluasi', [
            'form_date' => now()->toDateString(), 'shift' => 1,
            'checks' => [
                $full(),
                $full(['check_time' => '10:30', 'jig_option_id' => null, 'jig_other' => 'JIG khusus', 'problem' => 'Burry', 'compliance_option_ids' => [$apd->id], 'attachments' => [$photo()]]),
            ],
        ])->assertOk();

        $this->assertDatabaseCount('evaluation_checks', 2);
        $this->assertDatabaseHas('evaluation_checks', ['seq' => 2, 'jig_other' => 'JIG khusus']);
        $this->assertDatabaseHas('customers', ['name' => 'RINNAI INDONESIA']);
        $this->assertSame('07:30', substr(DB::table('evaluation_checks')->where('seq', 1)->where('planned_qty', 1200)->value('planned_start'), 0, 5));
        $this->assertDatabaseCount('evaluation_check_compliances', 3); // baris 1: APD + 5R, baris 2: APD

        // opsi grup lain tidak boleh dipakai sebagai kontrol kepatuhan
        $this->actingAs($karu)->postJson('/forms/evaluasi', [
            'form_date' => now()->toDateString(), 'shift' => 1,
            'checks' => [$full(['compliance_option_ids' => [$visualNg->id]])],
        ])->assertStatus(422)->assertJsonValidationErrors('checks.0.compliance_option_ids');

        // export ke template PDR/PP/13 yang sudah terisi
        $form = EvaluationForm::first();
        $res = $this->get("/forms/evaluasi/{$form->id}/export")->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'pdr').'.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $ws = IOFactory::load($tmp)->getSheet(0);
        @unlink($tmp);
        $this->assertSame('CUTP-501', $ws->getCell('B11')->getValue());
        $this->assertSame('Cust : RINNAI INDONESIA', $ws->getCell('C11')->getValue());
        $this->assertSame('NG', $ws->getCell('L11')->getValue());
        $this->assertStringContainsString('Penggunaan APD, 5R', $ws->getCell('N11')->getValue());
        $this->assertStringContainsString('Planning: start 07:30 · 1.200 pcs', $ws->getCell('P11')->getValue());
        $this->assertStringContainsString('Jig: JIG khusus', $ws->getCell('P13')->getValue());

        // karu lain tidak boleh mengunduh form ini
        $this->actingAs($this->makeUser('karu'))->get("/forms/evaluasi/{$form->id}/export")->assertForbidden();
    }

    public function test_master_manages_employees_and_accounts(): void
    {
        $master = $this->makeUser('master');
        $this->actingAs($master)->post('/employees', [
            'name' => 'Budi Santoso', 'code' => 'ka 099', 'id_card' => 'ID-123', 'gender' => 'L', 'position' => 'Kepala Regu',
            'create_account' => 1, 'username' => 'KA099', 'role' => 'karu', 'password' => 'awal12345',
        ])->assertRedirect(route('employees.index'));

        $emp = Employee::where('code', 'KA 099')->firstOrFail();
        $user = $emp->user;
        $this->assertSame('karu', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'employee', 'subject_id' => $emp->id, 'action' => 'create', 'user_id' => $master->id]);

        // nonaktifkan -> tercatat
        $this->actingAs($master)->put("/employees/{$emp->id}", [
            'name' => 'Budi Santoso', 'code' => 'KA 099', 'position' => 'Kepala Regu', 'username' => 'KA099', 'role' => 'karu', 'is_active' => 0,
        ])->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'user', 'subject_id' => $user->id, 'action' => 'update']);

        // kartu karyawan
        $this->actingAs($master)->getJson("/employees/{$emp->id}/card")->assertOk()
            ->assertJson(['name' => 'Budi Santoso', 'position' => 'Kepala Regu', 'role' => 'Karu (Kepala Regu)', 'active' => false]);

        // master tidak bisa menonaktifkan dirinya sendiri
        $this->actingAs($master)->put("/employees/{$master->employee_id}", [
            'name' => $master->name, 'username' => $master->username, 'role' => 'master', 'is_active' => 0,
        ])->assertSessionHas('error');
        $this->assertTrue($master->fresh()->is_active);
    }
}
