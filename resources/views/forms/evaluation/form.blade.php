<x-layouts.app :title="$form ? 'Edit Form Evaluasi' : 'Isi Form Evaluasi'">
@php
    $areas = \App\Models\Area::orderBy('name')->get(['id', 'name']);
    $cfg = [
        'config' => $config,
        'payload' => $payload,
        'today' => now()->toDateString(),
        'action' => $form ? route('forms.evaluation.update', $form) : route('forms.evaluation.store'),
        'method' => $form ? 'PUT' : 'POST',
        'uploadUrl' => route('attachments.store'),
    ];
    // Kolom ukur mengikuti lembar PDR/PP/13: OD (D1–D4), Tebal, Tinggi IB, Panjang
    $measures = [['d1', 'D1'], ['d2', 'D2'], ['d3', 'D3'], ['d4', 'D4'], ['thickness_mm', 'Tebal'], ['ib_height_mm', 'Tinggi IB'], ['length_mm', 'Panjang']];
@endphp
<div class="karu max-w-[1400px] mx-auto space-y-4" x-data="evaluationForm(@js($cfg))">
    <div class="text-[12.5px] px-1" style="color:var(--muted)">Daily Supervisory Control Kepala Regu Area · PDR/PP/13 · diisi saat ada masalah atau sewaktu-waktu.</div>

    <div class="max-w-3xl">
        @include('forms._header', ['form' => $form, 'areas' => $areas])
    </div>

    <datalist id="customers">
        <template x-for="c in cfg.customers" :key="c"><option :value="c"></option></template>
    </datalist>

    <div class="card overflow-hidden">
        <div class="card-pad !py-3 flex flex-wrap items-center justify-between gap-2 border-b" style="border-color:var(--border)">
            <div>
                <div class="card-title">Detail pengecekan</div>
                <div class="card-sub">Satu baris = satu jam cek / mesin. Centang yang sesuai, sama seperti di lembar kertas.</div>
            </div>
            <span class="chip chip-muted lg:hidden"><x-icon name="arrow-left-right" :size="13" />Geser ke samping</span>
        </div>

        <div class="sheet-scroll">
            <table class="sheet">
                <thead>
                    <tr>
                        <th rowspan="2" class="sheet-sticky w-[118px]">Jam cek</th>
                        <th rowspan="2" class="min-w-[150px]">Mesin *</th>
                        <th rowspan="2" class="min-w-[190px]">Customer &amp; dimensi</th>
                        <th colspan="7" class="sheet-group">Pengecekan aktual (mm)</th>
                        <th rowspan="2" class="min-w-[150px]">Jig</th>
                        <th rowspan="2" class="min-w-[150px]">Visual</th>
                        <th rowspan="2" class="min-w-[100px]">Actual cutting<br><span class="font-normal">(pcs)</span></th>
                        <th rowspan="2" class="min-w-[210px]">Kontrol kepatuhan</th>
                        <th rowspan="2" class="min-w-[220px]">Problem / keterangan</th>
                        <th rowspan="2" class="min-w-[170px]">Evidence foto</th>
                        <th rowspan="2" class="w-[52px]"><span class="sr-only">Aksi</span></th>
                    </tr>
                    <tr>
                        @foreach ($measures as [$k, $l])
                            <th class="sheet-sub min-w-[84px]">{{ $l }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(c, i) in f.checks" :key="i">
                        <tr :id="'check-' + i" :class="hasErr('checks.' + i) && 'has-err'">
                            {{-- jam cek + nomor baris (kolom ini tetap terlihat saat digeser) --}}
                            <td class="sheet-sticky">
                                <div class="text-[11px] font-semibold mb-1" style="color:var(--muted)" x-text="'#' + (i + 1)"></div>
                                <input type="time" class="input" x-model="c.check_time" aria-label="Jam cek">
                            </td>
                            <td>
                                <select class="input" x-model="c.machine_id" aria-label="Mesin"
                                        :class="err(`checks.${i}.machine_id`) && 'is-invalid'" :data-error="err(`checks.${i}.machine_id`) ? 1 : null">
                                    <option value="">Pilih</option>
                                    <template x-for="m in machinesFor()" :key="m.id"><option :value="m.id" x-text="m.code" :selected="String(m.id) === String(c.machine_id)"></option></template>
                                </select>
                                <span class="field-err" x-text="err(`checks.${i}.machine_id`)"></span>
                            </td>
                            <td>
                                <div class="space-y-1.5">
                                    <label class="sheet-pre"><span>Cust</span><input class="input" list="customers" x-model="c.customer" placeholder="Customer"></label>
                                    <label class="sheet-pre"><span>Size</span><input class="input" x-model="c.dimension" placeholder="Ø10 × 1,3 × 862"></label>
                                </div>
                            </td>
                            @foreach ($measures as [$k, $l])
                                <td>
                                    <input type="number" inputmode="decimal" step="any" min="0" class="input text-right tabular-nums" x-model="c.{{ $k }}" aria-label="{{ $l }}"
                                           :class="err(`checks.${i}.{{ $k }}`) && 'is-invalid'" :data-error="err(`checks.${i}.{{ $k }}`) ? 1 : null"
                                           :title="err(`checks.${i}.{{ $k }}`)">
                                </td>
                            @endforeach
                            <td>@include('forms.evaluation._ticks', ['g' => 'jig', 'l' => 'Jig'])</td>
                            <td>@include('forms.evaluation._ticks', ['g' => 'visual', 'l' => 'Visual'])</td>
                            <td>
                                <input type="number" inputmode="numeric" min="0" class="input text-right tabular-nums" x-model="c.actual_cutting" aria-label="Actual cutting">
                            </td>
                            <td>@include('forms.evaluation._ticks', ['g' => 'compliance', 'l' => 'Kontrol kepatuhan'])</td>
                            <td>
                                <textarea class="input sheet-note" rows="3" x-model="c.problem" placeholder="Temuan atau keterangan" aria-label="Problem / keterangan"></textarea>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="(p, pi) in c.photos" :key="pi">
                                        <div class="thumb !w-12 !h-12">
                                            <img :src="p.url" alt="Foto bukti">
                                            <div x-show="p.uploading" class="absolute inset-0 grid place-items-center text-[9.5px] font-semibold" style="background:rgba(0,0,0,.45);color:#fff">…</div>
                                            <div x-show="p.error" class="absolute inset-0" style="background:rgba(160,30,30,.75)" :title="p.error"></div>
                                            <button type="button" @click="removePhoto(c, pi)" class="absolute top-0.5 right-0.5 grid place-items-center w-5 h-5 rounded-full"
                                                    style="background:rgba(0,0,0,.6);color:#fff" aria-label="Hapus foto"><x-icon name="x" :size="11" :stroke="2.25" /></button>
                                        </div>
                                    </template>
                                    <label class="thumb !w-12 !h-12 grid place-items-center cursor-pointer" style="border-style:dashed" title="Tambah foto">
                                        <x-icon name="image-plus" :size="18" style="color:var(--ink-2)" />
                                        <input type="file" accept="image/*" multiple class="hidden" @change="addPhotos(c, $event.target.files); $event.target.value = ''">
                                    </label>
                                </div>
                            </td>
                            <td class="text-center">
                                <button type="button" class="icon-btn" style="color:var(--st-crit)" x-show="f.checks.length > 1" @click="removeCheck(i)" :aria-label="'Hapus baris #' + (i + 1)">
                                    <x-icon name="trash-2" :size="16" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="card-pad !py-3 border-t" style="border-color:var(--border)">
            <button type="button" class="btn w-full sm:w-auto" style="border-style:dashed" @click="addCheck()"><x-icon name="plus" :size="16" />Tambah baris pengecekan</button>
            <p class="text-[12px] mt-2" style="color:var(--muted)">Baris baru otomatis memakai mesin, customer &amp; size dari baris sebelumnya. Jam cek terisi jam sekarang.</p>
        </div>
    </div>

    <div class="sticky-bar -mx-4 lg:-mx-6 px-4 lg:px-6 py-3 flex items-center gap-3">
        <span class="text-[13px] flex-1 min-w-0" :style="message && 'color:var(--bad)'"
              x-text="message || (uploading ? `Mengunggah ${uploading} foto…` : `${f.checks.length} baris pengecekan`)"></span>
        <a href="{{ $form ? route('forms.evaluation.show', $form) : route('forms.evaluation.index') }}" class="btn">Batal</a>
        <button type="button" class="btn btn-primary !px-6" :disabled="saving || uploading > 0" @click="save()">
            <span x-show="!saving">Simpan</span><span x-show="saving" x-cloak>Menyimpan…</span>
        </button>
    </div>
</div>
</x-layouts.app>
