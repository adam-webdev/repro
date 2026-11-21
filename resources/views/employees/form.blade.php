<x-layouts.app :title="$employee->exists ? 'Edit Karyawan' : 'Tambah Karyawan'">
@php
    $isEdit = $employee->exists;
    $roleHelp = [
        'master' => 'Akses penuh, satu-satunya yang bisa menambah user, menetapkan role, dan mengaktifkan / menonaktifkan user.',
        'karu' => 'Mengisi Form Produksi (rutin) dan Form Evaluasi (saat ada masalah).',
        'produksi' => 'Import & export Excel, melihat semua data lewat tabel.',
        'viewer' => 'Hanya melihat dashboard / grafik.',
    ];
@endphp
<div class="max-w-3xl mx-auto space-y-4">
    <form method="POST" action="{{ $isEdit ? route('employees.update', $employee) : route('employees.store') }}" class="space-y-4"
          x-data="{ account: {{ $user || old('create_account') ? 'true' : 'false' }}, role: '{{ old('role', $user?->role ?? 'karu') }}' }">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="card card-pad space-y-3">
            <div class="card-title">Data karyawan</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block"><span class="label">Nama karyawan *</span>
                    <input name="name" value="{{ old('name', $employee->name) }}" required maxlength="100" class="input w-full @error('name') is-invalid @enderror"></label>
                <label class="block"><span class="label">Kode Karu</span>
                    <input name="code" value="{{ old('code', $employee->code) }}" maxlength="20" placeholder="mis. KA 044" class="input w-full @error('code') is-invalid @enderror">
                    @error('code')<span class="field-err">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="label">ID Card</span>
                    <input name="id_card" value="{{ old('id_card', $employee->id_card) }}" maxlength="50" class="input w-full @error('id_card') is-invalid @enderror">
                    @error('id_card')<span class="field-err">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="label">Jenis kelamin</span>
                    <select name="gender" class="input w-full">
                        <option value="">–</option>
                        @foreach (\App\Models\Employee::GENDERS as $k => $l)<option value="{{ $k }}" @selected(old('gender', $employee->gender) === $k)>{{ $l }}</option>@endforeach
                    </select></label>
                <label class="block"><span class="label">Jabatan</span>
                    <input name="position" value="{{ old('position', $employee->position) }}" maxlength="60" list="positions" placeholder="mis. Kepala Regu" class="input w-full">
                    <datalist id="positions"><option value="Kepala Regu"><option value="Admin Produksi"><option value="Supervisor"><option value="Foreman"><option value="Manager"></datalist></label>
                <label class="block"><span class="label">Email (opsional)</span>
                    <input name="email" type="email" value="{{ old('email', $employee->email) }}" class="input w-full @error('email') is-invalid @enderror"></label>
            </div>
        </div>

        <div class="card card-pad space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="card-title">Akun login</div>
                    <div class="card-sub">Nama di akun selalu mengikuti nama karyawan.</div>
                </div>
                @if (! $user)
                    <label class="flex items-center gap-2 text-[13.5px]"><input type="checkbox" name="create_account" value="1" x-model="account"> Buat akun</label>
                @endif
            </div>

            <div x-show="account" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="block"><span class="label">Username *</span>
                        <input name="username" value="{{ old('username', $user?->username ?? ($employee->code ? str_replace(' ', '', $employee->code) : '')) }}" maxlength="50" placeholder="mis. KA044"
                               class="input w-full @error('username') is-invalid @enderror" autocomplete="off">
                        @error('username')<span class="field-err">{{ $message }}</span>@enderror</label>
                    <label class="block"><span class="label">{{ $user ? 'Reset password (kosongkan jika tidak diubah)' : 'Password awal *' }}</span>
                        <input name="password" type="text" minlength="8" class="input w-full @error('password') is-invalid @enderror" autocomplete="new-password" placeholder="min. 8 karakter">
                        @error('password')<span class="field-err">{{ $message }}</span>@enderror</label>
                </div>
                <div>
                    <span class="label">Role *</span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach (\App\Models\User::ROLES as $k => $l)
                            <label class="flex items-start gap-2.5 rounded-xl border p-3 cursor-pointer" :style="role === '{{ $k }}' ? 'border-color:var(--ink-2);background:var(--hover)' : 'border-color:var(--border)'">
                                <input type="radio" name="role" value="{{ $k }}" x-model="role" class="mt-1">
                                <span><span class="block text-[13.5px] font-semibold">{{ $l }}</span><span class="block text-[12px]" style="color:var(--muted)">{{ $roleHelp[$k] }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @if ($user)
                    <label class="flex items-center gap-2 text-[13.5px]">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Akun aktif (bisa login)
                    </label>
                @endif
                <p class="text-[12px]" style="color:var(--muted)">Setelah dibuat / direset, pengguna wajib mengganti password saat login berikutnya.</p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button class="btn btn-primary">Simpan</button>
            <a href="{{ route('employees.index') }}" class="btn">Batal</a>
        </div>
    </form>

    @if ($isEdit && ! $employee->trashed() && $user?->id !== auth()->id())
        <form method="POST" action="{{ route('employees.destroy', $employee) }}" x-data @submit="if (!confirm('Hapus {{ $employee->name }}? Akun dinonaktifkan, riwayat tetap tersimpan.')) $event.preventDefault()">
            @csrf @method('DELETE')
            <button class="btn btn-danger">Hapus karyawan</button>
        </form>
    @endif

    @isset($logs)
        @include('forms._history', ['logs' => $logs])
    @endisset
</div>
</x-layouts.app>
