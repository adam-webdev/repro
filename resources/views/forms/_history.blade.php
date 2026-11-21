{{-- Riwayat (audit trail) satu data: siapa, aksi apa, kapan, apa yang berubah. --}}
<div class="card">
    <div class="card-pad !pb-2">
        <div class="card-title">Riwayat perubahan</div>
        <div class="card-sub">Setiap tambah, edit, dan hapus tercatat otomatis.</div>
    </div>
    <div class="px-5 pb-4">
        @forelse ($logs as $log)
            <div class="py-3 border-t first:border-t-0" style="border-color:var(--border)" x-data="{ more: false }">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                    <span class="chip {{ $log->actionChip() }}">{{ $log->actionLabel() }}</span>
                    <x-user-link :employee="$log->employee_id" :name="$log->user_name" />
                    <span style="color:var(--muted)">{{ $log->roleLabel() }}{{ $log->user_position ? ' · '.$log->user_position : '' }}</span>
                    <span class="ml-auto text-[12px]" style="color:var(--muted)">{{ $log->created_at->translatedFormat('d M Y, H:i') }}</span>
                </div>
                @php $rows = $log->action === 'create' ? [] : $log->changeRows(); @endphp
                @if ($rows)
                    <button type="button" class="text-[12.5px] mt-1.5 underline underline-offset-2" style="color:var(--ink-2)" @click="more = !more"
                            x-text="more ? 'Sembunyikan' : 'Lihat {{ count($rows) }} perubahan'"></button>
                    <div x-show="more" x-cloak class="mt-2 overflow-x-auto">
                        <table class="tbl !text-[12.5px]">
                            <thead><tr><th>Kolom</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
                            <tbody>
                            @foreach ($rows as [$field, $old, $new])
                                <tr><td>{{ $field }}</td><td style="color:var(--muted)">{{ $old ?? '–' }}</td><td class="!whitespace-normal">{{ $new }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-[13px] py-2" style="color:var(--muted)">Belum ada riwayat.</p>
        @endforelse
    </div>
</div>
