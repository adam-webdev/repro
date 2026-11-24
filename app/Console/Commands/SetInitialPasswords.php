<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Set password awal semua akun aktif agar bisa dibagikan:
 *  - Admin Master : email & password dari opsi --master-email / --master-password.
 *  - Karu         : nama depan + "@@123", mis. Kurniawan@@123.
 *  - Role lain    : nama tanpa spasi + "@@123", mis. AdminShift1@@123.
 * Selain Admin Master, akun wajib mengganti password saat login pertama.
 */
#[Signature('users:set-initial-passwords
    {--master-email= : Email login Admin Master}
    {--master-password= : Password Admin Master}
    {--force : Jalankan tanpa pertanyaan konfirmasi}')]
#[Description('Set password awal semua akun (karu: NamaDepan@@123) untuk dibagikan')]
class SetInitialPasswords extends Command
{
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Password semua akun aktif akan diganti. Lanjutkan?')) {
            return self::FAILURE;
        }

        $rows = [];
        foreach (User::where('is_active', true)->orderBy('username')->get()->sortBy(fn ($u) => array_search($u->role, ['master', 'produksi', 'viewer', 'karu'], true)) as $user) {
            if ($user->role === 'master') {
                $email = $this->option('master-email');
                $password = $this->option('master-password');
                if (! $email || ! $password) {
                    $rows[] = [$user->roleLabel(), $user->name, $user->username, $user->email, '(tidak diubah)'];

                    continue;
                }
                $user->forceFill(['email' => $email, 'password' => $password, 'must_change_password' => false])->save();
            } else {
                $password = self::initialPassword($user);
                $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
            }
            $rows[] = [$user->roleLabel(), $user->name, $user->username, $user->email ?? '–', $password];
        }

        $this->table(['Role', 'Nama', 'Username', 'Email', 'Password'], $rows);
        $this->info('Selesai. Selain Admin Master, pengguna wajib ganti password saat login pertama.');

        return self::SUCCESS;
    }

    /** Karu: nama depan; role lain: nama lengkap tanpa spasi. Selalu diakhiri "@@123". */
    public static function initialPassword(User $user): string
    {
        $name = trim(preg_replace('/[^\pL\pN ]/u', '', $user->name));
        $base = $user->role === 'karu' ? Str::before($name, ' ') : str_replace(' ', '', Str::title($name));

        return ($base ?: $user->username).'@@123';
    }
}
