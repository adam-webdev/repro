<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\ProductionForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs($produksi)->get('/forms/monitoring')->assertOk()->assertSee('Laporan karu vs data admin');
        $this->actingAs($master)->getJson('/forms/monitoring/data')->assertOk()->assertJsonStructure(['karu' => ['kpi', 'crosscheck', 'problems']]);

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
        $dtLaser = FormOption::create(['group' => 'dt', 'machine_type' => 'laser', 'label' => 'Ganti Gas']);
        $dtExs = FormOption::create(['group' => 'dt', 'machine_type' => 'exs', 'label' => 'Pembersihan Dies']);

        $payload = fn (array $item = []) => [
            'form_date' => now()->subDays(3)->toDateString(), // back date
            'shift' => 2,
            'items' => [array_merge([
                'machine_type' => 'laser', 'machine_id' => $laser->id, 'working_minutes' => 420, 'cutting_minutes' => 360, 'ok_qty' => 1000,
                'problems' => [
                    ['kind' => 'setup', 'option_id' => $setup->id, 'minutes' => 20],
                    ['kind' => 'dt', 'option_id' => $dtLaser->id, 'minutes' => 25],
                    ['kind' => 'dt', 'option_id' => null, 'other_text' => 'Tunggu forklift', 'minutes' => 15],
                ],
            ], $item)],
        ];

        // opsi DT khusus EXS tidak boleh dipakai di mesin laser
        $bad = $payload(['problems' => [['kind' => 'dt', 'option_id' => $dtExs->id, 'minutes' => 5]]]);
        $this->actingAs($karu)->postJson('/forms/produksi', $bad)->assertStatus(422)->assertJsonValidationErrors('items.0.problems.0.option_id');

        // "Lainnya" tanpa teks ditolak
        $bad = $payload(['problems' => [['kind' => 'dt', 'option_id' => null, 'other_text' => '', 'minutes' => 5]]]);
        $this->actingAs($karu)->postJson('/forms/produksi', $bad)->assertStatus(422);

        $this->actingAs($karu)->postJson('/forms/produksi', $payload())->assertOk()->assertJsonStructure(['redirect']);
        $form = ProductionForm::firstOrFail();
        $this->assertSame($karu->id, $form->user_id);
        $this->assertSame($karu->employee_id, $form->employee_id);
        $this->assertSame(3, $form->items->first()->problems()->count());
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'production_form', 'subject_id' => $form->id, 'action' => 'create', 'user_id' => $karu->id]);

        // edit: hasil OK berubah -> tercatat sebelum & sesudah
        $this->actingAs($karu)->putJson("/forms/produksi/{$form->id}", $payload(['ok_qty' => 950]))->assertOk();
        $log = ActivityLog::where('subject_id', $form->id)->where('action', 'update')->firstOrFail();
        $this->assertSame(1000, $log->changes['old']['Mesin #1 OK']);
        $this->assertSame(950, $log->changes['new']['Mesin #1 OK']);
        $this->assertSame('Jabatan karu', $log->user_position);
        $this->assertContains(['Mesin #1 OK', '1000', '950'], $log->changeRows());
        $this->actingAs($karu)->get("/forms/produksi/{$form->id}")->assertOk()->assertSee('Lihat 1 perubahan');

        // karu lain tidak boleh melihat / mengubah
        $other = $this->makeUser('karu');
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
        $apd = FormOption::create(['group' => 'compliance', 'label' => 'Penggunaan APD']);

        $this->actingAs($karu)->postJson('/forms/evaluasi', [
            'form_date' => now()->toDateString(), 'shift' => 1,
            'checks' => [
                ['check_time' => '08:15', 'machine_id' => $m->id, 'customer' => 'PT. RINNAI INDONESIA', 'od_mm' => 10.02, 'visual_option_id' => $visualNg->id, 'compliance_option_id' => $apd->id],
                ['check_time' => '10:30', 'machine_id' => $m->id, 'jig_option_id' => null, 'jig_other' => 'JIG khusus', 'problem' => 'Burry'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('evaluation_checks', 2);
        $this->assertDatabaseHas('evaluation_checks', ['seq' => 2, 'jig_other' => 'JIG khusus']);
        $this->assertDatabaseHas('customers', ['name' => 'RINNAI INDONESIA']);

        // export ke template PDR/PP/13 yang sudah terisi
        $form = \App\Models\EvaluationForm::first();
        $res = $this->get("/forms/evaluasi/{$form->id}/export")->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'pdr').'.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getSheet(0);
        @unlink($tmp);
        $this->assertSame('CUTP-501', $ws->getCell('B11')->getValue());
        $this->assertSame('Cust : RINNAI INDONESIA', $ws->getCell('C11')->getValue());
        $this->assertSame('NG', $ws->getCell('L11')->getValue());
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
