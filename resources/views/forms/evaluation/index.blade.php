<x-layouts.app title="Form Evaluasi">
    @if ($canCreate)
        <x-slot:actions>
            <a href="{{ route('forms.evaluation.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /><span class="hidden sm:inline">Isi form</span></a>
        </x-slot:actions>
    @endif
<div class="space-y-4">
    @include('forms._filters')

    @forelse ($forms as $form)
        @php
            $ng = $form->checks->filter(fn ($c) => $c->visual && $c->visual->label !== 'OK')->count();
            $photos = $form->checks->sum('attachments_count');
        @endphp
        <a href="{{ route('forms.evaluation.show', $form) }}" class="card card-pad !py-3.5 flex flex-wrap items-center gap-x-5 gap-y-2 hover:bg-[var(--hover)] transition-colors">
            <div class="w-[118px] shrink-0">
                <div class="text-[14px] font-semibold">{{ $form->form_date->translatedFormat('D, d M') }}</div>
                <div class="text-[12px]" style="color:var(--muted)">Shift {{ $form->shift }} · {{ $form->area?->name ?? 'Semua area' }}</div>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-[13.5px] font-medium truncate">{{ $form->checks->map(fn ($c) => $c->machine?->code)->filter()->unique()->join(', ') }}</div>
                <div class="text-[12px]" style="color:var(--muted)">{{ $form->user?->name }} · diperbarui {{ $form->updated_at->diffForHumans() }}{{ $form->updater && $form->updated_by !== $form->user_id ? ' oleh '.$form->updater->name : '' }}</div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <span class="chip chip-muted">{{ $form->checks->count() }} pengecekan</span>
                @if ($ng)<span class="chip chip-crit">{{ $ng }} visual tidak OK</span>@endif
                @if ($photos)<span class="chip chip-muted">{{ $photos }} foto</span>@endif
            </div>
        </a>
    @empty
        <div class="card card-pad text-center py-12">
            <div class="text-[15px] font-semibold">Belum ada form pada periode ini</div>
            <p class="text-[13px] mt-1" style="color:var(--muted)">Form evaluasi diisi saat ada masalah atau sewaktu-waktu.</p>
            @if ($canCreate)<a href="{{ route('forms.evaluation.create') }}" class="btn btn-primary mt-4"><x-icon name="plus" :size="16" />Isi Form Evaluasi</a>@endif
        </div>
    @endforelse

    {{ $forms->links() }}
</div>
</x-layouts.app>
