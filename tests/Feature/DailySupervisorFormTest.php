<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Attachment;
use App\Models\EvaluationForm;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DailySupervisorFormTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    private FormOption $visualPipe;

    private FormOption $apd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = Machine::create(['code' => 'CUTP-501', 'type' => 'CUTP']);
        $this->apd = FormOption::create(['group' => 'compliance', 'label' => 'Penggunaan APD', 'sort' => 1]);
        $this->visualPipe = FormOption::create(['group' => 'compliance', 'label' => FormOption::VISUAL_PIPE, 'sort' => 2]);
        FormOption::create(['group' => 'visual', 'label' => 'OK']);
        FormOption::create(['group' => 'jig', 'label' => 'JIG In']);
    }

    private function karu(string $name): User
    {
        return User::create(['name' => $name, 'username' => strtolower(str_replace(' ', '', $name)), 'password' => 'rahasia123', 'role' => 'karu', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function check(array $overrides = []): array
    {
        return array_merge([
            'check_time' => '08:00', 'machine_id' => $this->machine->id, 'customer' => 'RINNAI INDONESIA', 'dimension' => 'Ø10 × 1,3 × 862',
            'd1' => 10, 'd2' => 10, 'd3' => 10, 'd4' => 10, 'thickness_mm' => 1.3, 'ib_height_mm' => 0.2, 'length_mm' => 862,
            'jig_option_id' => FormOption::where('group', 'jig')->value('id'), 'visual_option_id' => FormOption::where('group', 'visual')->value('id'),
            'compliance_option_ids' => [$this->apd->id], 'actual_cutting' => 100, 'problem' => 'Tidak ada',
        ], $overrides);
    }

    private function photo(User $owner): int
    {
        return DB::table('attachments')->insertGetId(['user_id' => $owner->id, 'path' => 'x.jpg', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_visual_pipe_check_requires_a_photo_or_image_link(): void
    {
        $karu = $this->karu('Karu Satu');
        $post = fn (array $check) => $this->actingAs($karu)->postJson('/forms/evaluasi', [
            'form_date' => now()->toDateString(), 'shift' => 1, 'checks' => [$this->check($check)],
        ]);

        // tanpa centang visual pipa: foto tidak wajib, link yang terkirim diabaikan
        $post(['pipe_photo_url' => 'https://contoh.test/a.jpg'])->assertOk();
        $this->assertNull(DB::table('evaluation_checks')->value('pipe_photo_url'));

        $pipe = ['compliance_option_ids' => [$this->apd->id, $this->visualPipe->id]];
        $post($pipe)->assertStatus(422)->assertJsonValidationErrors('checks.0.pipe_attachments');
        $post($pipe + ['pipe_photo_url' => 'bukan link'])->assertStatus(422)->assertJsonValidationErrors('checks.0.pipe_photo_url');

        $post($pipe + ['pipe_photo_url' => 'https://contoh.test/pipa.jpg'])->assertOk();
        $this->assertDatabaseHas('evaluation_checks', ['pipe_photo_url' => 'https://contoh.test/pipa.jpg']);

        $photoId = $this->photo($karu);
        $post($pipe + ['pipe_attachments' => [$photoId]])->assertOk();
        $this->assertSame(Attachment::VISUAL_PIPE, Attachment::find($photoId)->purpose);
        $check = EvaluationForm::latest('id')->first()->checks->first();
        $this->assertCount(1, $check->pipePhotos);
        $this->assertCount(0, $check->attachments, 'foto visual pipa terpisah dari evidence umum');
    }

    public function test_dates_then_areas_then_one_combined_sheet_from_all_karus(): void
    {
        $area = Area::create(['name' => 'Area 01']);
        Area::create(['name' => 'Area 02']);
        $karuA = $this->karu('Karu Pagi');
        $karuB = $this->karu('Karu Malam');
        $date = now()->subDay()->toDateString();
        $photo = $this->photo($karuA);

        $this->actingAs($karuA)->postJson('/forms/evaluasi', ['form_date' => $date, 'shift' => 1, 'area_id' => $area->id, 'checks' => [
            $this->check(['check_time' => '10:00', 'problem' => 'Cek siang']),
            $this->check(['check_time' => '08:00', 'problem' => 'Cek pagi', 'attachments' => [$photo]]),
        ]])->assertOk()->assertJsonPath('redirect', route('forms.evaluation.area', [$date, $area->id]));
        $this->actingAs($karuB)->postJson('/forms/evaluasi', ['form_date' => $date, 'shift' => 2, 'area_id' => $area->id, 'checks' => [
            $this->check(['check_time' => '01:30', 'problem' => 'Cek lewat tengah malam']),
            $this->check(['check_time' => '19:00', 'problem' => 'Cek malam']),
        ]])->assertOk();

        $this->actingAs($karuB)->get('/forms/evaluasi')->assertOk()
            ->assertSee(route('forms.evaluation.day', $date))->assertSee('4 pengecekan');
        $this->actingAs($karuB)->get(route('forms.evaluation.day', $date))->assertOk()
            ->assertSee('Area 01')->assertSee('Area 02')->assertSee('Belum ada pengecekan')
            ->assertSee(route('forms.evaluation.area', [$date, $area->id]));

        // satu lembar gabungan, urut shift lalu jam (01:30 shift 2 setelah 19:00)
        $this->actingAs($karuB)->get(route('forms.evaluation.area', [$date, $area->id]))->assertOk()
            ->assertSeeInOrder(['Cek pagi', 'Cek siang', 'Cek malam', 'Cek lewat tengah malam'])
            ->assertSee('Karu Pagi')->assertSee('Karu Malam');
        // foto karu lain bisa dibuka dari lembar gabungan
        $this->actingAs($karuB)->get(route('attachments.show', $photo))->assertNotFound(); // file dummy tidak ada, tapi tidak 403

        $res = $this->actingAs($karuB)->get(route('forms.evaluation.area-export', [$date, $area->id]))->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'pdr').'.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $ws = IOFactory::load($tmp)->getSheet(0);
        @unlink($tmp);
        $this->assertSame('Karu Malam, Karu Pagi', $ws->getCell('N5')->getValue());
        $this->assertSame('Area 01', $ws->getCell('N6')->getValue());
        $this->assertStringContainsString('Karu: Karu Pagi · Shift 1', $ws->getCell('P11')->getValue());
        $this->assertStringContainsString('Foto: '.route('attachments.show', $photo), $ws->getCell('P11')->getValue());
        $this->assertStringContainsString('Cek lewat tengah malam', $ws->getCell('P17')->getValue());
    }

    public function test_karu_deletes_only_their_own_check(): void
    {
        $area = Area::create(['name' => 'Area 01']);
        $owner = $this->karu('Karu Satu');
        $other = $this->karu('Karu Dua');
        $date = now()->subDay()->toDateString();
        $this->actingAs($owner)->postJson('/forms/evaluasi', ['form_date' => $date, 'shift' => 1, 'area_id' => $area->id, 'checks' => [
            $this->check(['check_time' => '08:00', 'problem' => 'Salah ketik']),
            $this->check(['check_time' => '09:00', 'problem' => 'Benar']),
        ]])->assertOk();
        $form = EvaluationForm::firstOrFail();
        [$typo, $kept] = $form->checks->all();

        $this->actingAs($other)->get(route('forms.evaluation.area', [$date, $area->id]))->assertOk()
            ->assertDontSee(route('forms.evaluation.check.destroy', $typo));
        $this->actingAs($other)->delete(route('forms.evaluation.check.destroy', $typo))->assertForbidden();

        $this->actingAs($owner)->delete(route('forms.evaluation.check.destroy', $typo))
            ->assertRedirect(route('forms.evaluation.area', [$date, $area->id]));
        $this->assertDatabaseMissing('evaluation_checks', ['id' => $typo->id]);
        $this->assertDatabaseHas('evaluation_checks', ['id' => $kept->id, 'seq' => 1]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'evaluation_form', 'subject_id' => $form->id, 'action' => 'update']);

        // pengecekan terakhir dihapus → form ikut dihapus (tetap tersimpan sebagai riwayat)
        $this->actingAs($owner)->delete(route('forms.evaluation.check.destroy', $kept))->assertRedirect();
        $this->assertSoftDeleted('evaluation_forms', ['id' => $form->id, 'deleted_by' => $owner->id]);
    }

    public function test_karu_production_form_export_lists_planning_and_photo_links(): void
    {
        $karu = $this->karu('Karu Satu');
        $other = $this->karu('Karu Dua');
        $photo = $this->photo($karu);
        $payload = fn (string $pro, array $photos) => [
            'form_date' => now()->subDay()->toDateString(), 'shift' => 1,
            'items' => [[
                'machine_type' => 'umum', 'machine_id' => $this->machine->id, 'order_no' => $pro, 'dimension' => 'Ø10',
                'planned_start' => '07:30', 'planned_qty' => 1000, 'working_minutes' => 600, 'cutting_minutes' => 500,
                'std_output' => 100, 'act_output' => 90, 'ok_qty' => 800, 'note' => 'Lancar', 'attachments' => $photos,
            ]],
        ];
        $this->actingAs($karu)->postJson('/forms/produksi', $payload('408894', [$photo]))->assertOk();
        $this->actingAs($other)->postJson('/forms/produksi', $payload('999999', []))->assertOk();

        $res = $this->actingAs($karu)->get('/forms/produksi/export')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'rek').'.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $rows = IOFactory::load($tmp)->getSheet(0)->toArray();
        @unlink($tmp);

        $this->assertCount(2, $rows, 'karu hanya mengekspor form miliknya');
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('408894', (string) $row['No. PRO']);
        $this->assertSame(['07:30', 1000, 80], [$row['Start Jam Planning'], (int) $row['Qty Planning (pcs)'], (int) $row['Productivity %']]);
        $this->assertSame("Lancar\nFoto: ".route('attachments.show', $photo), $row['Keterangan']);
    }
}
