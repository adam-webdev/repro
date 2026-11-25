<x-layouts.app title="Karyawan & Akun">
    <x-slot:actions>
        <a href="{{ route('employees.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /><span class="hidden sm:inline">Tambah karyawan</span></a>
    </x-slot:actions>
<div class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @foreach (\App\Models\User::ROLES as $key => $label)
            <a href="{{ route('employees.index', ['role' => $key]) }}" class="card card-pad !py-3 hover:bg-[var(--hover)]">
                <div class="text-[12px]" style="color:var(--muted)">{{ $label }}</div>
                <div class="text-[22px] font-semibold">{{ $counts[$key] ?? 0 }}</div>
                <div class="text-[11.5px]" style="color:var(--muted)">akun aktif</div>
            </a>
        @endforeach
    </div>

    <form method="GET" class="card card-pad !py-3 flex flex-wrap items-end gap-2.5">
        <label class="block flex-1 min-w-[200px]"><span class="label">Cari</span>
            <input name="q" value="{{ request('q') }}" class="input w-full" placeholder="Nama, kode, username"></label>
        <label class="block"><span class="label">Role</span>
            <select name="role" class="input">
                <option value="">Semua</option>
                @foreach (\App\Models\User::ROLES as $k => $l)<option value="{{ $k }}" @selected(request('role') === $k)>{{ $l }}</option>@endforeach
            </select>
        </label>
        <div><span class="label">Status</span>
            <div class="seg">
                @foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'dihapus' => 'Dihapus'] as $k => $l)
                    <button name="status" value="{{ $k }}" class="{{ $status === $k ? 'on' : '' }}">{{ $l }}</button>
                @endforeach
            </div>
        </div>
        <button class="btn">Cari</button>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Kode</th><th>Nama</th><th>Jabatan</th><th>Akun</th><th>Role</th><th>Status</th><th>Login terakhir</th><th></th></tr></thead>
                <tbody>
                @forelse ($employees as $e)
                    @php $u = $e->user; @endphp
                    <tr>
                        <td class="font-medium">{{ $e->code ?? '–' }}</td>
                        <td><x-user-link :employee="$e->id" :name="$e->name" /></td>
                        <td>{{ $e->position ?? '–' }}</td>
                        <td>{{ $u?->username ?? '–' }}</td>
                        <td>@if ($u)<span class="chip chip-muted">{{ $u->roleLabel() }}</span>@else<span style="color:var(--muted)">Belum punya akun</span>@endif</td>
                        <td>
                            @if ($e->trashed())<span class="chip chip-crit">Dihapus</span>
                            @elseif (! $u)<span style="color:var(--muted)">–</span>
                            @elseif ($u->is_active)<span class="chip chip-ok">Aktif</span>
                            @else<span class="chip chip-crit">Nonaktif</span>@endif
                        </td>
                        <td style="color:var(--muted)">{{ $u?->last_login_at?->format('d/m/Y H:i') ?? '–' }}</td>
                        <td class="num">
                            @if ($e->trashed())
                                <form method="POST" action="{{ route('employees.restore', $e) }}">@csrf<button class="btn !h-8">Pulihkan</button></form>
                            @else
                                <a href="{{ route('employees.edit', $e) }}" class="btn !h-8">Edit</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-8" style="color:var(--muted)">Tidak ada data.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $employees->links() }}
</div>
</x-layouts.app>
