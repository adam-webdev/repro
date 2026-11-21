<x-layouts.app title="Log Aktivitas">
<div class="space-y-4">
    <form method="GET" class="card card-pad !py-3 flex flex-wrap items-end gap-2.5">
        <label class="block"><span class="label">Dari</span><input type="date" name="from" value="{{ $from }}" class="input"></label>
        <label class="block"><span class="label">Sampai</span><input type="date" name="to" value="{{ $to }}" class="input"></label>
        <label class="block"><span class="label">Pengguna</span>
            <select name="user_id" class="input">
                <option value="">Semua</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }} ({{ $u->username }})</option>@endforeach
            </select></label>
        <label class="block"><span class="label">Aksi</span>
            <select name="action" class="input">
                <option value="">Semua</option>
                @foreach (\App\Models\ActivityLog::ACTIONS as $k => $l)<option value="{{ $k }}" @selected(request('action') === $k)>{{ $l }}</option>@endforeach
            </select></label>
        <label class="block"><span class="label">Data</span>
            <select name="type" class="input">
                <option value="">Semua</option>
                @foreach (\App\Models\ActivityLog::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach
            </select></label>
        <label class="block flex-1 min-w-[160px]"><span class="label">Cari</span><input name="q" value="{{ request('q') }}" class="input w-full" placeholder="mis. 14/11/2026"></label>
        <button class="btn">Terapkan</button>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Data</th><th>Perubahan</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    @php $rows = $log->changeRows(); $url = $log->subjectUrl(); @endphp
                    <tr x-data="{ more: false }" class="align-top">
                        <td style="color:var(--ink-2)">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td><x-user-link :employee="$log->employee_id" :name="$log->user_name" :sub="$log->roleLabel().($log->user_position ? ' · '.$log->user_position : '')" /></td>
                        <td><span class="chip {{ $log->actionChip() }}">{{ $log->actionLabel() }}</span></td>
                        <td class="!whitespace-normal min-w-[220px]">
                            <div class="text-[12px]" style="color:var(--muted)">{{ $log->typeLabel() }}</div>
                            @if ($url)<a href="{{ $url }}" class="hover:underline">{{ $log->subject_label }}</a>@else{{ $log->subject_label }}@endif
                        </td>
                        <td class="!whitespace-normal min-w-[260px]">
                            @if ($rows)
                                <button type="button" class="text-[12.5px] underline underline-offset-2" style="color:var(--ink-2)" @click="more = !more"
                                        x-text="more ? 'Sembunyikan' : '{{ count($rows) }} kolom'"></button>
                                <div x-show="more" x-cloak class="mt-1.5 space-y-0.5 text-[12.5px]">
                                    @foreach (array_slice($rows, 0, 80) as [$f, $old, $new])
                                        <div><span style="color:var(--muted)">{{ $f }}:</span>
                                            @if ($old !== null)<s style="color:var(--muted)">{{ \Illuminate\Support\Str::limit($old, 60) }}</s> → @endif{{ \Illuminate\Support\Str::limit($new, 80) }}</div>
                                    @endforeach
                                </div>
                            @else
                                <span style="color:var(--muted)">–</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-8" style="color:var(--muted)">Belum ada aktivitas pada periode ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $logs->links() }}
</div>
</x-layouts.app>
