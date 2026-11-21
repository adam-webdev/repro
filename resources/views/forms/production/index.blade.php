<x-layouts.app title="Form Produksi">
    @if ($canCreate)
        <x-slot:actions>
            <a href="{{ route('forms.production.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /><span class="hidden sm:inline">Isi form</span></a>
        </x-slot:actions>
    @endif
<div class="space-y-4">
    @include('forms._filters')

    @forelse ($forms as $form)
        @php
            $dt = $form->items->flatMap->problems->where('kind', 'dt')->sum('minutes');
            $su = $form->items->flatMap->problems->where('kind', 'setup')->sum('minutes');
            $photos = $form->items->sum('attachments_count');
        @endphp
        <a href="{{ route('forms.production.show', $form) }}" class="card card-pad !py-3.5 flex flex-wrap items-center gap-x-5 gap-y-2 hover:bg-[var(--hover)] transition-colors">
            <div class="w-[118px] shrink-0">
                <div class="text-[14px] font-semibold">{{ $form->form_date->translatedFormat('D, d M') }}</div>
                <div class="text-[12px]" style="color:var(--muted)">Shift {{ $form->shift }} · {{ $form->area?->name ?? 'Semua area' }}</div>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-[13.5px] font-medium truncate">{{ $form->items->map(fn ($i) => $i->machine?->code)->filter()->join(', ') }}</div>
                <div class="text-[12px]" style="color:var(--muted)">{{ $form->user?->name }} · diperbarui {{ $form->updated_at->diffForHumans() }}{{ $form->updater && $form->updated_by !== $form->user_id ? ' oleh '.$form->updater->name : '' }}</div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <span class="chip chip-muted">{{ $form->items->count() }} mesin</span>
                <span class="chip chip-muted">OK {{ number_format($form->items->sum('ok_qty'), 0, ',', '.') }}</span>
                @if ($su)<span class="chip chip-muted">Set up {{ $su }} mnt</span>@endif
                @if ($dt)<span class="chip chip-warn">DT {{ $dt }} mnt</span>@endif
                @if ($photos)<span class="chip chip-muted">{{ $photos }} foto</span>@endif
            </div>
        </a>
    @empty
        <div class="card card-pad text-center py-12">
            <div class="text-[15px] font-semibold">Belum ada form pada periode ini</div>
            <p class="text-[13px] mt-1" style="color:var(--muted)">Ubah filter tanggal, atau isi form baru.</p>
            @if ($canCreate)<a href="{{ route('forms.production.create') }}" class="btn btn-primary mt-4"><x-icon name="plus" :size="16" />Isi Form Produksi</a>@endif
        </div>
    @endforelse

    {{ $forms->links() }}
</div>
</x-layouts.app>
