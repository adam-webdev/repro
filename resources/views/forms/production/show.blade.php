<x-layouts.app title="Form Produksi">
@php
    $n = fn ($v, $d = 0, $unit = '') => $v === null ? '–' : number_format((float) $v, $d, ',', '.').$unit;
@endphp
<div class="max-w-4xl mx-auto space-y-4">
    @include('forms._show_header', ['kind' => 'Form Produksi', 'editRoute' => 'forms.production.edit', 'deleteRoute' => 'forms.production.destroy'])

    @foreach ($form->items as $i => $it)
        @php
            $setup = $it->problems->where('kind', 'setup');
            $dt = $it->problems->where('kind', 'dt');
        @endphp
        <div class="card">
            <div class="card-pad !pb-3 flex flex-wrap items-center gap-3">
                <span class="grid place-items-center w-8 h-8 rounded-full text-[13px] font-semibold" style="background:var(--surface-2)">{{ $i + 1 }}</span>
                <div class="min-w-0 flex-1">
                    <div class="text-[15px] font-semibold">{{ $it->machine?->code }}</div>
                    <div class="text-[12.5px]" style="color:var(--muted)">{{ config('production.machine_types.'.$it->machine_type.'.label') }}{{ $it->dimension ? ' · '.$it->dimension : '' }}</div>
                </div>
            </div>
            <div class="px-5 pb-5 space-y-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach ([['Waktu kerja', $n($it->working_minutes, 0, ' mnt')], ['Cutting time', $n($it->cutting_minutes, 0, ' mnt')], ['Std output', $n($it->std_output, 1, ' pcs/j')], ['Act output', $n($it->act_output, 1, ' pcs/j')], ['Hasil OK', $n($it->ok_qty, 0, ' pcs')], ['2nd grade', $n($it->second_grade_qty, 0, ' pcs')], ['NC', $n($it->nc_qty, 0, ' pcs')], ['Capaian', $it->std_output && $it->act_output ? $n($it->act_output / $it->std_output * 100, 1).'%' : '–']] as [$l, $v])
                        <div class="rounded-xl px-3 py-2.5" style="background:var(--surface-2)">
                            <div class="text-[11.5px]" style="color:var(--muted)">{{ $l }}</div>
                            <div class="text-[15px] font-semibold mt-0.5">{{ $v }}</div>
                        </div>
                    @endforeach
                </div>

                <div>
                    <div class="label">Problem</div>
                    @if ($it->problems->isEmpty())
                        <span class="text-[13px]" style="color:var(--muted)">Tidak ada set up / downtime.</span>
                    @else
                        <table class="tbl">
                            <thead><tr><th>Jenis</th><th>Keterangan</th><th class="num">Menit</th></tr></thead>
                            <tbody>
                            @foreach ($it->problems as $p)
                                <tr>
                                    <td><span class="chip {{ $p->kind === 'setup' ? 'chip-muted' : 'chip-warn' }}">{{ $p->kind === 'setup' ? 'SET UP' : 'DT' }}</span></td>
                                    <td class="!whitespace-normal">{{ \App\Models\FormOption::display($p->option, $p->other_text) }}</td>
                                    <td class="num">{{ $n($p->minutes) }}</td>
                                </tr>
                            @endforeach
                            <tr><td colspan="2" style="color:var(--muted)">Total set up {{ $setup->sum('minutes') }} mnt · DT {{ $dt->sum('minutes') }} mnt</td><td class="num font-semibold">{{ $it->problems->sum('minutes') }}</td></tr>
                            </tbody>
                        </table>
                    @endif
                </div>

                @if ($it->note)
                    <div><div class="label">Keterangan</div><div class="text-[13.5px]">{{ $it->note }}</div></div>
                @endif
                <div>
                    <div class="label">Dokumentasi foto</div>
                    @include('forms._gallery', ['photos' => $it->attachments])
                </div>
            </div>
        </div>
    @endforeach

    @include('forms._history')

    <a href="{{ route('forms.production.index') }}" class="btn"><x-icon name="arrow-left" :size="16" />Kembali</a>
</div>
</x-layouts.app>
