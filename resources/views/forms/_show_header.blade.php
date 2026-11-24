{{-- Kepala halaman detail form: identitas, siapa input / ubah, tombol edit & hapus. --}}
@if ($form->trashed())
    <x-alert type="crit">Form ini sudah dihapus {{ $form->deleted_at->format('d/m/Y H:i') }}. Data tetap disimpan untuk penelusuran.</x-alert>
@endif
<div class="card card-pad">
    <div class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
            <div class="text-[12px]" style="color:var(--muted)">{{ $kind }}</div>
            <div class="text-[18px] font-semibold mt-0.5">{{ $form->form_date->translatedFormat('l, d F Y') }} · Shift {{ $form->shift }}</div>
            <div class="text-[13px] mt-0.5" style="color:var(--ink-2)">{{ $form->area?->name ?? 'Semua area' }}</div>
        </div>
        <div class="flex flex-wrap gap-2" x-data>
            @isset($exportRoute)
                <a href="{{ route($exportRoute, $form) }}" class="btn"><x-icon name="download" :size="16" />{{ $exportLabel ?? 'Export Excel' }}</a>
            @endisset
            @if ($canEdit)
                <a href="{{ route($editRoute, $form) }}" class="btn">Edit</a>
                <form method="POST" action="{{ route($deleteRoute, $form) }}" @submit="if (!confirm('Hapus form ini? Data tetap tersimpan di riwayat.')) $event.preventDefault()">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger">Hapus</button>
                </form>
            @endif
        </div>
    </div>
    {{-- Nama karu & siapa input / ubah hanya untuk admin; karu cukup melihat form miliknya sendiri. --}}
    @if (auth()->user()->role !== 'karu')
    <dl class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-[13px]">
        <div>
            <dt class="label !mb-0.5">Kepala Regu</dt>
            <dd><x-user-link :employee="$form->employee_id" :name="$form->user?->name" :sub="$form->employee?->code" /></dd>
        </div>
        <div>
            <dt class="label !mb-0.5">Diinput</dt>
            <dd><x-user-link :employee="$form->creator?->employee_id" :name="$form->creator?->name" :sub="$form->created_at->format('d/m/Y H:i')" /></dd>
        </div>
        <div>
            <dt class="label !mb-0.5">Terakhir diubah</dt>
            <dd><x-user-link :employee="$form->updater?->employee_id" :name="$form->updater?->name" :sub="$form->updated_at->format('d/m/Y H:i')" /></dd>
        </div>
    </dl>
    @endif
</div>
