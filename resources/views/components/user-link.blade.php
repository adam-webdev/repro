@props(['employee' => null, 'name' => null, 'sub' => null])
{{-- Nama pengguna yang bisa diklik -> kartu karyawan (nama, jabatan, role). --}}
@if ($employee)
    <button type="button" {{ $attributes->merge(['class' => 'text-left font-medium hover:underline underline-offset-2 decoration-dotted']) }}
            @click.stop="$dispatch('employee-card', '{{ route('employees.card', $employee) }}')">{{ $name ?? '-' }}</button>
@else
    <span {{ $attributes }}>{{ $name ?? 'Sistem' }}</span>
@endif
@if ($sub)
    <span class="block text-[11.5px]" style="color:var(--muted)">{{ $sub }}</span>
@endif
