<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Employee;
use App\Models\EvaluationForm;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KaruAreaAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Area $area1;

    private Area $area2;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->area1 = Area::create(['name' => 'Area 01']);
        $this->area2 = Area::create(['name' => 'Area 02']);
        $this->machine = Machine::create(['code' => 'CUTP-501', 'type' => 'CUTP']);
        FormOption::create(['group' => 'compliance', 'label' => 'Penggunaan APD', 'sort' => 1]);
        FormOption::create(['group' => 'visual', 'label' => 'OK']);
        FormOption::create(['group' => 'jig', 'label' => 'JIG In']);
    }

    private function user(string $username, array $attrs = []): User
    {
        return User::create($attrs + ['name' => ucfirst($username), 'username' => $username, 'password' => 'rahasia123', 'role' => 'karu', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function evaluation(array $header): array
    {
        return $header + [
            'form_date' => now()->toDateString(), 'shift' => 1,
            'checks' => [[
                'check_time' => '08:00', 'machine_id' => $this->machine->id, 'customer' => 'RINNAI INDONESIA', 'dimension' => 'Ø10 × 1,3 × 862',
                'd1' => 10, 'd2' => 10, 'd3' => 10, 'd4' => 10, 'thickness_mm' => 1.3, 'ib_height_mm' => 0.2, 'length_mm' => 862,
                'jig_option_id' => FormOption::where('group', 'jig')->value('id'), 'visual_option_id' => FormOption::where('group', 'visual')->value('id'),
                'compliance_option_ids' => [FormOption::where('group', 'compliance')->value('id')], 'actual_cutting' => 100, 'problem' => 'Tidak ada',
            ]],
        ];
    }

    public function test_fixed_karu_always_saves_to_their_assigned_area(): void
    {
        $karu = $this->user('ka044', ['area_id' => $this->area1->id]);
        $this->actingAs($karu)->get('/forms/produksi/create')->assertOk()->assertSee('Area terkunci sesuai area tugas Anda.');

        $this->actingAs($karu)->postJson('/forms/evaluasi', $this->evaluation(['area_id' => $this->area2->id, 'substitute_for_id' => $karu->id]))->assertOk();

        $form = EvaluationForm::sole();
        $this->assertSame($this->area1->id, $form->area_id, 'area dikunci ke area tugas');
        $this->assertFalse((bool) $form->by_substitute);
        $this->assertNull($form->substitute_for_id);

        $date = now()->toDateString();
        $this->actingAs($karu)->get("/forms/evaluasi/hari/{$date}")->assertOk()
            ->assertSee(route('forms.evaluation.create', ['date' => $date, 'area_id' => $this->area1->id]))
            ->assertDontSee(route('forms.evaluation.create', ['date' => $date, 'area_id' => $this->area2->id]));
    }

    public function test_substitute_karu_picks_any_area_and_is_labelled_in_the_area_detail(): void
    {
        $fixed = $this->user('ka044', ['name' => 'Kurniawan', 'area_id' => $this->area1->id]);
        $substitute = $this->user('budi', ['name' => 'Budi', 'is_substitute' => true]);
        $this->actingAs($substitute)->get('/forms/evaluasi/create')->assertOk()->assertSee('Menggantikan karu (opsional)');

        $this->actingAs($substitute)->postJson('/forms/evaluasi', $this->evaluation([]))
            ->assertStatus(422)->assertJsonValidationErrors('area_id');

        $this->actingAs($substitute)->postJson('/forms/evaluasi', $this->evaluation(['area_id' => $this->area2->id, 'substitute_for_id' => $fixed->id]))->assertOk();

        $form = EvaluationForm::sole();
        $this->assertSame($this->area2->id, $form->area_id);
        $this->assertTrue((bool) $form->by_substitute);
        $this->assertSame('Budi (Pengganti Kurniawan)', $form->karuLabel());

        $this->actingAs($substitute)->get('/forms/evaluasi/hari/'.now()->toDateString().'/'.$this->area2->id)
            ->assertOk()->assertSee('Pengganti Kurniawan');
    }

    public function test_new_substitute_account_starts_inactive_until_master_activates_it(): void
    {
        $master = $this->user('admin', ['role' => 'master']);

        $this->actingAs($master)->post('/employees', [
            'name' => 'Budi Pengganti', 'create_account' => 1, 'username' => 'budi', 'password' => 'rahasia123',
            'role' => 'karu', 'is_substitute' => 1, 'area_id' => $this->area1->id,
        ])->assertRedirect(route('employees.index'));

        $budi = User::where('username', 'budi')->sole();
        $this->assertTrue($budi->is_substitute);
        $this->assertFalse($budi->is_active);
        $this->assertNull($budi->area_id, 'pengganti tidak terikat area');

        auth()->logout();
        $this->post('/login', ['login' => 'budi', 'password' => 'rahasia123'])->assertSessionHasErrors('login');

        $this->actingAs($master)->post(route('employees.toggle-active', $budi->employee_id))->assertRedirect();
        $this->assertTrue($budi->fresh()->is_active);
    }

    public function test_only_master_can_toggle_account_status(): void
    {
        $karu = $this->user('ka044');
        $other = $this->user('budi', ['is_active' => false]);
        $other->forceFill(['employee_id' => Employee::create(['name' => 'Budi'])->id])->save();

        $this->actingAs($karu)->post(route('employees.toggle-active', $other->employee_id))->assertForbidden();
        $this->assertFalse($other->fresh()->is_active);
    }
}
