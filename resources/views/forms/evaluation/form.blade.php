<x-layouts.app :title="$form ? 'Edit Form Daily Supervisor' : 'Isi Form Daily Supervisor'">
@php
    $areas = \App\Models\Area::orderBy('name')->get(['id', 'name']);
    $cfg = [
        'config' => $config,
        'payload' => $payload,
        'today' => now()->toDateString(),
        'action' => $form ? route('forms.evaluation.update', $form) : route('forms.evaluation.store'),
        'method' => $form ? 'PUT' : 'POST',
        'uploadUrl' => route('attachments.store'),
        'karu' => \App\Services\KaruFormService::headerContext(auth()->user(), $form),
        'defaults' => $defaults ?? null,
    ];
    // Kolom ukur mengikuti lembar PDR/PP/13: OD (D1–D4), Tebal, Tinggi IB, Panjang
    $measures = [['d1', 'D1'], ['d2', 'D2'], ['d3', 'D3'], ['d4', 'D4'], ['thickness_mm', 'Tebal'], ['ib_height_mm', 'Tinggi IB'], ['length_mm', 'Panjang']];
@endphp
<div class="karu max-w-[1400px] mx-auto space-y-4" x-data="evaluationForm(@js($cfg))">
    <div class="text-[12.5px] px-1" style="color:var(--muted)">Form Daily Supervisor Kepala Regu Area · PDR/PP/13 · diisi saat ada masalah atau sewaktu-waktu.</div>

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
                <div class="text-[12.5px] mt-1 flex items-center gap-1.5" style="color:var(--ink-2)"><x-icon name="info" :size="14" /><span><b>Kolom bertanda * wajib diisi.</b> Foto opsional, kecuali <b>Pengecekan visual pipa</b> dicentang (wajib foto atau link gambar). Jika tidak ada masalah, tulis "Tidak ada" di Problem.</span></div>
            </div>
        </div>

        {{-- HP / tablet: satu pengecekan = satu kartu, kolom disusun ke bawah --}}
        <div class="lg:hidden divide-y" style="border-color:var(--border)">
            <template x-for="(c, i) in f.checks" :key="i">
                <div :id="'check-' + i" class="p-4 space-y-4" :style="hasErr('checks.' + i) && 'background:color-mix(in srgb, var(--st-crit) 5%, var(--surface))'">
                    <div class="flex items-center gap-3">
                        <span class="grid place-items-center w-8 h-8 rounded-full text-[13px] font-semibold shrink-0" style="background:var(--surface-2)" x-text="i + 1"></span>
                        <span class="min-w-0 flex-1 text-[14px] font-semibold truncate" x-text="summary(c)"></span>
                        <button type="button" class="icon-btn" style="color:var(--st-crit)" x-show="f.checks.length > 1" @click="removeCheck(i)" :aria-label="'Hapus pengecekan #' + (i + 1)">
                            <x-icon name="trash-2" :size="16" />
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block min-w-0">
                            <span class="label">Jam cek *</span>
                            <input type="time" class="input" x-model="c.check_time" :class="err(`checks.${i}.check_time`) && 'is-invalid'" :data-error="err(`checks.${i}.check_time`) ? 1 : null">
                            <span class="field-err" x-text="err(`checks.${i}.check_time`)"></span>
                        </label>
                        <label class="block min-w-0">
                            <span class="label">Mesin *</span>
                            <select class="input" x-model="c.machine_id" :class="err(`checks.${i}.machine_id`) && 'is-invalid'" :data-error="err(`checks.${i}.machine_id`) ? 1 : null">
                                <option value="">Pilih</option>
                                <template x-for="m in machinesFor()" :key="m.id"><option :value="m.id" x-text="m.code" :selected="String(m.id) === String(c.machine_id)"></option></template>
                            </select>
                            <span class="field-err" x-text="err(`checks.${i}.machine_id`)"></span>
                        </label>
                        <label class="block min-w-0 col-span-2 sm:col-span-1">
                            <span class="label">Customer *</span>
                            <input class="input" list="customers" x-model="c.customer" placeholder="Customer" :class="err(`checks.${i}.customer`) && 'is-invalid'" :data-error="err(`checks.${i}.customer`) ? 1 : null">
                            <span class="field-err" x-text="err(`checks.${i}.customer`)"></span>
                        </label>
                        <label class="block min-w-0 col-span-2 sm:col-span-1">
                            <span class="label">Size / dimensi *</span>
                            <input class="input" x-model="c.dimension" placeholder="Ø10 × 1,3 × 862" :class="err(`checks.${i}.dimension`) && 'is-invalid'" :data-error="err(`checks.${i}.dimension`) ? 1 : null">
                            <span class="field-err" x-text="err(`checks.${i}.dimension`)"></span>
                        </label>
                    </div>
                    <div>
                        <div class="text-[12px] font-semibold uppercase tracking-wider mb-2" style="color:var(--muted)">Pengecekan aktual (mm) *</div>
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                            @foreach ($measures as [$k, $l])
                                <label class="block min-w-0">
                                    <span class="label">{{ $l }}</span>
                                    <input type="number" inputmode="decimal" step="any" min="0" class="input" x-model="c.{{ $k }}"
                                           :class="err(`checks.${i}.{{ $k }}`) && 'is-invalid'" :data-error="err(`checks.${i}.{{ $k }}`) ? 1 : null">
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="min-w-0"><span class="label">Jig *</span>@include('forms.evaluation._ticks', ['g' => 'jig', 'l' => 'Jig'])</div>
                        <div class="min-w-0"><span class="label">Visual *</span>@include('forms.evaluation._ticks', ['g' => 'visual', 'l' => 'Visual'])</div>
                    </div>
                    <label class="block">
                        <span class="label">Actual cutting (pcs) *</span>
                        <input type="number" inputmode="numeric" min="0" class="input" x-model="c.actual_cutting" :class="err(`checks.${i}.actual_cutting`) && 'is-invalid'" :data-error="err(`checks.${i}.actual_cutting`) ? 1 : null">
                        <span class="field-err" x-text="err(`checks.${i}.actual_cutting`)"></span>
                    </label>
                    <div><span class="label">Kontrol kepatuhan *</span>@include('forms.evaluation._ticks_multi')</div>
                    <label class="block">
                        <span class="label">Problem / keterangan *</span>
                        <textarea class="input" rows="3" x-model="c.problem" placeholder="Temuan / keterangan, atau &quot;Tidak ada&quot;"
                                  :class="err(`checks.${i}.problem`) && 'is-invalid'" :data-error="err(`checks.${i}.problem`) ? 1 : null"></textarea>
                        <span class="field-err" x-text="err(`checks.${i}.problem`)"></span>
                    </label>
                    <div>
                        <span class="label">Evidence foto (opsional)</span>
                        @include('forms.evaluation._photo_strip', ['key' => 'photos'])
                        <span class="field-err" x-text="err(`checks.${i}.attachments`)"></span>
                    </div>
                </div>
            </template>
        </div>

        <div class="sheet-scroll hidden lg:block">
            <table class="sheet">
                <thead>
                    <tr>
                        <th rowspan="2" class="sheet-sticky w-[104px]">Jam cek *</th>
                        <th rowspan="2" class="min-w-[124px]">Mesin *</th>
                        <th rowspan="2" class="min-w-[190px]">Customer &amp; dimensi *</th>
                        <th colspan="7" class="sheet-group">Pengecekan aktual (mm) *</th>
                        <th rowspan="2" class="min-w-[140px]">Jig *</th>
                        <th rowspan="2" class="min-w-[140px]">Visual *</th>
                        <th rowspan="2" class="w-[92px]">Actual cutting<br><span class="font-normal">(pcs) *</span></th>
                        <th rowspan="2" class="min-w-[230px]">Kontrol kepatuhan *</th>
                        <th rowspan="2" class="min-w-[220px]">Problem / keterangan *</th>
                        <th rowspan="2" class="min-w-[170px]">Evidence foto<br><span class="font-normal">(opsional)</span></th>
                        <th rowspan="2" class="w-[52px]"><span class="sr-only">Aksi</span></th>
                    </tr>
                    <tr>
                        @foreach ($measures as [$k, $l])
                            <th class="sheet-sub w-[68px]">{{ $l }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(c, i) in f.checks" :key="i">
                        <tr :id="'check-row-' + i" :class="hasErr('checks.' + i) && 'has-err'">
                            {{-- jam cek + nomor baris (kolom ini tetap terlihat saat digeser) --}}
                            <td class="sheet-sticky">
                                <div class="text-[11px] font-semibold mb-1" style="color:var(--muted)" x-text="'#' + (i + 1)"></div>
                                <input type="time" class="input" x-model="c.check_time" aria-label="Jam cek"
                                       :class="err(`checks.${i}.check_time`) && 'is-invalid'" :data-error="err(`checks.${i}.check_time`) ? 1 : null" :title="err(`checks.${i}.check_time`)">
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
                                    <label class="sheet-pre"><span>Cust</span><input class="input" list="customers" x-model="c.customer" placeholder="Customer"
                                        :class="err(`checks.${i}.customer`) && 'is-invalid'" :data-error="err(`checks.${i}.customer`) ? 1 : null" :title="err(`checks.${i}.customer`)"></label>
                                    <label class="sheet-pre"><span>Size</span><input class="input" x-model="c.dimension" placeholder="Ø10 × 1,3 × 862"
                                        :class="err(`checks.${i}.dimension`) && 'is-invalid'" :data-error="err(`checks.${i}.dimension`) ? 1 : null" :title="err(`checks.${i}.dimension`)"></label>
                                </div>
                            </td>
                            @foreach ($measures as [$k, $l])
                                <td>
                                    <input type="number" inputmode="decimal" step="any" min="0" class="input sheet-num w-[60px]" x-model="c.{{ $k }}" aria-label="{{ $l }}"
                                           :class="err(`checks.${i}.{{ $k }}`) && 'is-invalid'" :data-error="err(`checks.${i}.{{ $k }}`) ? 1 : null"
                                           :title="err(`checks.${i}.{{ $k }}`)">
                                </td>
                            @endforeach
                            <td>@include('forms.evaluation._ticks', ['g' => 'jig', 'l' => 'Jig'])</td>
                            <td>@include('forms.evaluation._ticks', ['g' => 'visual', 'l' => 'Visual'])</td>
                            <td>
                                <input type="number" inputmode="numeric" min="0" class="input sheet-num w-[80px]" x-model="c.actual_cutting" aria-label="Actual cutting"
                                       :class="err(`checks.${i}.actual_cutting`) && 'is-invalid'" :data-error="err(`checks.${i}.actual_cutting`) ? 1 : null" :title="err(`checks.${i}.actual_cutting`)">
                            </td>
                            <td>@include('forms.evaluation._ticks_multi')</td>
                            <td>
                                <textarea class="input sheet-note" rows="3" x-model="c.problem" placeholder="Temuan / keterangan, atau &quot;Tidak ada&quot;" aria-label="Problem / keterangan"
                                          :class="err(`checks.${i}.problem`) && 'is-invalid'" :data-error="err(`checks.${i}.problem`) ? 1 : null"></textarea>
                                <span class="field-err" x-text="err(`checks.${i}.problem`)"></span>
                            </td>
                            <td>
                                @include('forms.evaluation._photo_strip', ['key' => 'photos'])
                                <span class="field-err" :data-error="err(`checks.${i}.attachments`) ? 1 : null" x-text="err(`checks.${i}.attachments`)"></span>
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

    <div class="sticky-bar -mx-4 lg:-mx-6 px-4 lg:px-6 py-3 flex flex-wrap items-center gap-x-3 gap-y-2">
        <span class="text-[13px] flex-1 min-w-[150px]" :style="message && 'color:var(--bad)'"
              x-text="message || (uploading ? `Mengunggah ${uploading} foto…` : `${f.checks.length} baris pengecekan`)"></span>
        <a href="{{ $form ? route('forms.evaluation.show', $form) : route('forms.evaluation.index') }}" class="btn">Batal</a>
        <button type="button" class="btn btn-primary !px-6" :disabled="saving || uploading > 0" @click="save()">
            <span x-show="!saving">Simpan</span><span x-show="saving" x-cloak>Menyimpan…</span>
        </button>
    </div>
</div>
</x-layouts.app>
