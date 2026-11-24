<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetInitialPasswordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_passwords_follow_name_pattern_and_master_gets_given_credentials(): void
    {
        User::create(['name' => 'Admin Master', 'username' => 'admin', 'email' => 'old@local', 'password' => 'lama12345', 'role' => 'master', 'is_active' => true]);
        User::create(['name' => 'Wakhyu Hendrik Subekti', 'username' => 'KA072', 'password' => 'lama12345', 'role' => 'karu', 'is_active' => true]);
        User::create(['name' => 'Admin Shift 1', 'username' => 'admin1', 'password' => 'lama12345', 'role' => 'produksi', 'is_active' => true]);

        $this->artisan('users:set-initial-passwords', ['--master-email' => 'rizky99@example.com', '--master-password' => '@@Password123@@', '--force' => true])
            ->assertSuccessful();

        $this->post('/login', ['login' => 'rizky99@example.com', 'password' => '@@Password123@@'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $this->post('/login', ['login' => 'KA072', 'password' => 'Wakhyu@@123'])->assertRedirect(route('profile.edit')); // wajib ganti password
        $this->post('/logout');
        $this->post('/login', ['login' => 'admin1', 'password' => 'AdminShift1@@123'])->assertRedirect(route('profile.edit'));
    }
}
