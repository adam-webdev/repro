<x-layouts.app title="Profil">
@php $e = $user->employee; @endphp
<div class="max-w-2xl mx-auto space-y-4">
    @if ($user->must_change_password)
        <x-alert type="warn">Anda masih memakai password awal. Ganti password dulu sebelum memakai menu lain.</x-alert>
    @endif

    <div class="card card-pad flex items-center gap-4">
        <div class="grid place-items-center w-14 h-14 rounded-full text-[18px] font-semibold" style="background:var(--surface-2)">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
        <div class="min-w-0">
            <div class="text-[18px] font-semibold">{{ $user->name }}</div>
            <div class="text-[13px]" style="color:var(--ink-2)">{{ collect([$e?->position, $e?->code])->filter()->join(' · ') ?: '–' }}</div>
            <div class="flex flex-wrap gap-1.5 mt-1.5"><span class="chip chip-muted">{{ $user->roleLabel() }}</span><span class="chip chip-muted">{{ $user->username }}</span></div>
        </div>
    </div>

    <form method="POST" action="{{ route('profile.update') }}" class="card card-pad space-y-3">
        @csrf @method('PUT')
        <div class="card-title">Data diri</div>
        <dl class="grid grid-cols-2 gap-3 text-[13.5px]">
            <div><dt class="label !mb-0.5">ID Card</dt><dd>{{ $e?->id_card ?? '–' }}</dd></div>
            <div><dt class="label !mb-0.5">Kode</dt><dd>{{ $e?->code ?? '–' }}</dd></div>
        </dl>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="block"><span class="label">Jenis kelamin</span>
                <select name="gender" class="input w-full !h-11">
                    <option value="">–</option>
                    @foreach (\App\Models\Employee::GENDERS as $k => $l)<option value="{{ $k }}" @selected(old('gender', $e?->gender) === $k)>{{ $l }}</option>@endforeach
                </select></label>
            <label class="block"><span class="label">Email (opsional)</span>
                <input name="email" type="email" value="{{ old('email', $user->email) }}" class="input w-full !h-11"></label>
        </div>
        <p class="text-[12px]" style="color:var(--muted)">Nama, kode, ID card, jabatan, dan role diatur oleh Admin Master.</p>
        <button class="btn">Simpan profil</button>
    </form>

    <form method="POST" action="{{ route('profile.password') }}" class="card card-pad space-y-3">
        @csrf @method('PUT')
        <div class="card-title">Ganti password</div>
        <div class="grid grid-cols-1 gap-3">
            <label class="block"><span class="label">Password lama</span>
                <input name="current_password" type="password" required autocomplete="current-password" class="input w-full !h-11 @error('current_password') is-invalid @enderror">
                @error('current_password')<span class="field-err">{{ $message }}</span>@enderror</label>
            <label class="block"><span class="label">Password baru</span>
                <input name="password" type="password" required autocomplete="new-password" class="input w-full !h-11 @error('password') is-invalid @enderror">
                @error('password')<span class="field-err">{{ $message }}</span>@enderror</label>
            <label class="block"><span class="label">Ulangi password baru</span>
                <input name="password_confirmation" type="password" required autocomplete="new-password" class="input w-full !h-11"></label>
        </div>
        <p class="text-[12px]" style="color:var(--muted)">Minimal 8 karakter, berisi huruf dan angka.</p>
        <button class="btn btn-primary">Ganti password</button>
    </form>
</div>
</x-layouts.app>
