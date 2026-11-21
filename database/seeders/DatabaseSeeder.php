<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\DefectType;
use App\Models\DowntimeCategory;
use App\Models\DowntimeReason;
use App\Models\Employee;
use App\Models\FormOption;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Mesin & area (Sheet2)
        foreach (config('production.machines') as $code => [$area, $building]) {
            $areaId = $area ? Area::firstOrCreate(['name' => $area])->id : null;
            Machine::updateOrCreate(['code' => $code], [
                'type' => strtok($code, '-'),
                'area_id' => $areaId,
                'building' => $building,
            ]);
        }

        // Jenis reject
        $i = 0;
        foreach (config('production.defects') as $key => $name) {
            DefectType::updateOrCreate(['key' => $key], ['name' => $name, 'sort' => ++$i]);
        }

        // Kategori & alasan downtime
        $i = 0;
        $cats = [];
        foreach (config('production.downtime_categories') as $key => $name) {
            $cats[$key] = DowntimeCategory::updateOrCreate(['key' => $key], ['name' => $name, 'sort' => ++$i])->id;
        }
        $i = 0;
        foreach (config('production.downtimes') as $key => $def) {
            DowntimeReason::updateOrCreate(['key' => $key], [
                'downtime_category_id' => $cats[$def[0]],
                'name' => $def[1],
                'frequency_key' => $def[2] ?? null,
                'sort' => ++$i,
            ]);
        }

        $this->seedFormOptions();
        $this->seedPeople();
    }

    /** Pilihan dropdown Form Produksi & Form Evaluasi (dari config/production.php). */
    private function seedFormOptions(): void
    {
        $reasons = DowntimeReason::pluck('id', 'key')->all();
        $opts = config('production.form_options');
        $put = function (string $group, ?string $type, array $items) use ($reasons) {
            $i = 0;
            foreach ($items as $label => $key) {
                if (is_int($label)) {
                    [$label, $key] = [$key, null];
                }
                FormOption::updateOrCreate(
                    ['group' => $group, 'machine_type' => $type, 'label' => $label],
                    ['downtime_reason_id' => $key ? ($reasons[$key] ?? null) : null, 'sort' => ++$i, 'is_active' => true],
                );
            }
        };
        $put('setup', null, $opts['setup']);
        $put('dt', null, $opts['dt']['umum']);
        $put('dt', 'laser', $opts['dt']['laser']);
        $put('dt', 'exs', $opts['dt']['exs']);
        foreach (['compliance', 'visual', 'jig'] as $g) {
            $put($g, null, $opts[$g]);
        }
    }

    /**
     * Akun awal. Semua password awal = "password" dan WAJIB diganti saat login pertama.
     *  - Admin Master : username "admin"
     *  - 12 Karu      : username = kode tanpa spasi, mis. "KA044"
     */
    private function seedPeople(): void
    {
        $master = User::withTrashed()->where('username', 'admin')->first();
        if (! $master) {
            $emp = Employee::firstOrCreate(['name' => 'Admin Master', 'code' => null], ['position' => 'Administrator Sistem']);
            User::create([
                'name' => 'Admin Master', 'username' => 'admin', 'password' => 'password', 'role' => 'master',
                'employee_id' => $emp->id, 'must_change_password' => true,
            ]);
        }

        foreach (config('production.karu') as [$code, $name]) {
            $emp = Employee::withTrashed()->firstOrCreate(['code' => $code], ['name' => $name, 'position' => 'Kepala Regu']);
            $username = str_replace(' ', '', $code);
            if (! User::withTrashed()->where('employee_id', $emp->id)->orWhere('username', $username)->exists()) {
                User::create([
                    'name' => $name, 'username' => $username, 'password' => 'password', 'role' => 'karu',
                    'employee_id' => $emp->id, 'must_change_password' => true,
                ]);
            }
        }

        // akun lama yang belum terhubung ke data karyawan
        foreach (User::withTrashed()->whereNull('employee_id')->get() as $u) {
            $emp = Employee::create(['name' => $u->name, 'position' => $u->roleLabel()]);
            $u->employee_id = $emp->id;
            $u->saveQuietly();
        }
    }
}
