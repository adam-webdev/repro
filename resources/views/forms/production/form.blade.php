<x-layouts.app :title="$form ? 'Edit Form Rekapan' : 'Isi Form Rekapan'">
@php
    $areas = \App\Models\Area::orderBy('name')->get(['id', 'name']);
    $cfg = [
        'config' => $config,
        'payload' => $payload,
        'today' => now()->toDateString(),
        'action' => $form ? route('forms.production.update', $form) : route('forms.production.store'),
        'method' => $form ? 'PUT' : 'POST',
        'uploadUrl' => route('attachments.store'),
        'karu' => \App\Services\KaruFormService::headerContext(auth()->user(), $form),
    ];
@endphp
<div class="karu max-w-3xl mx-auto space-y-4" x-data="productionForm(@js($cfg))">

    @include('forms._header', ['form' => $form, 'areas' => $areas])

    <div class="alert alert-info text-[13px]">
        <x-icon name="info" :size="16" />
        <span>Kolom bertanda <b>*</b> wajib diisi. Yang boleh dikosongkan hanya <b>Reject</b>, <b>Hasil NC</b>, <b>Keterangan</b> dan foto (opsional); bila Reject diisi, <b>alasan reject</b> wajib.
            <b>Start jam &amp; qty planning</b> dipakai untuk Productivity di Monitoring Karu. Set Up / Bongkar / Downtime diisi bila ada, dan total menitnya maksimal <b>waktu kerja − cutting time</b>.</span>
    </div>

    <template x-for="(it, i) in f.items" :key="i">
        <div class="card" :id="'item-' + i" :class="hasErr('items.' + i) && '!border-[var(--bad)]'">
            {{-- judul mesin (ketuk untuk buka / tutup) --}}
            <button type="button" class="w-full flex items-center gap-3 card-pad !py-3.5 text-left" @click="it.open = !it.open">
                <span class="grid place-items-center w-8 h-8 rounded-full text-[13px] font-semibold shrink-0" style="background:var(--surface-2)" x-text="i + 1"></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[14.5px] font-semibold" x-text="(machineCode(it.machine_id) || 'Mesin belum dipilih') + (it.order_no ? ' · PRO ' + it.order_no : '')"></span>
                    <span class="block text-[12px] truncate" style="color:var(--muted)" x-text="summary(it)"></span>
                </span>
                <span class="chip chip-crit" x-show="hasErr('items.' + i)">Perlu dicek</span>
                <x-icon name="chevron-down" :size="18" class="chev" ::style="it.open && 'transform:rotate(180deg)'" />
            </button>

            <div class="reveal" :class="it.open && 'is-open'" :inert="!it.open"><div><div class="px-4 sm:px-5 pb-5 space-y-5 border-t" style="border-color:var(--border)">
                {{-- B. Data produksi --}}
                <div class="pt-4 space-y-3">
                    <div class="text-[12px] font-semibold uppercase tracking-wider" style="color:var(--muted)">Data produksi</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="label">Jenis mesin *</span>
                            <select class="input" x-model="it.machine_type" @change="changeType(it)">
                                <option value="">Semua jenis</option>
                                <template x-for="t in cfg.machineTypes" :key="t.key"><option :value="t.key" x-text="t.label" :selected="t.key === it.machine_type"></option></template>
                            </select>
                        </label>
                        <label class="block">
                            <span class="label">Mesin *</span>
                            <select class="input" x-model="it.machine_id" @change="pickMachine(it)" :class="err(`items.${i}.machine_id`) && 'is-invalid'" :data-error="err(`items.${i}.machine_id`) ? 1 : null">
                                <option value="">Pilih mesin</option>
                                <template x-for="m in machinesFor(it)" :key="m.id"><option :value="m.id" x-text="m.code" :selected="String(m.id) === String(it.machine_id)"></option></template>
                            </select>
                            <span class="field-err" x-text="err(`items.${i}.machine_id`)"></span>
                        </label>
                        <label class="block">
                            <span class="label">No. PRO *</span>
                            <input class="input" x-model="it.order_no" inputmode="numeric" maxlength="20" placeholder="No. order, mis. 408894" :class="err(`items.${i}.order_no`) && 'is-invalid'" :data-error="err(`items.${i}.order_no`) ? 1 : null">
                            <span class="field-err" x-text="err(`items.${i}.order_no`)"></span>
                        </label>
                        <label class="block">
                            <span class="label">Dimensi *</span>
                            <input class="input" x-model="it.dimension" placeholder="mis. Ø10 × 1,3 × 862" :class="err(`items.${i}.dimension`) && 'is-invalid'" :data-error="err(`items.${i}.dimension`) ? 1 : null">
                            <span class="field-err" x-text="err(`items.${i}.dimension`)"></span>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block min-w-0">
                                <span class="label">Start jam planning *</span>
                                <input type="time" class="input" x-model="it.planned_start" :class="err(`items.${i}.planned_start`) && 'is-invalid'" :data-error="err(`items.${i}.planned_start`) ? 1 : null">
                                <span class="field-err" x-text="err(`items.${i}.planned_start`)"></span>
                            </label>
                            <label class="block min-w-0">
                                <span class="label">Qty planning (pcs) *</span>
                                <input type="number" inputmode="numeric" min="0" class="input" x-model="it.planned_qty" :class="err(`items.${i}.planned_qty`) && 'is-invalid'" :data-error="err(`items.${i}.planned_qty`) ? 1 : null">
                                <span class="field-err" x-text="err(`items.${i}.planned_qty`)"></span>
                            </label>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block min-w-0">
                                <span class="label">Waktu kerja (mnt) *</span>
                                <input type="number" inputmode="numeric" min="0" class="input" x-model="it.working_minutes" :class="err(`items.${i}.working_minutes`) && 'is-invalid'" :data-error="err(`items.${i}.working_minutes`) ? 1 : null">
                                <span class="field-err" x-text="err(`items.${i}.working_minutes`)"></span>
                            </label>
                            <label class="block min-w-0">
                                <span class="label">Cutting time (mnt) *</span>
                                <input type="number" inputmode="numeric" min="0" class="input" x-model="it.cutting_minutes" :class="err(`items.${i}.cutting_minutes`) && 'is-invalid'" :data-error="err(`items.${i}.cutting_minutes`) ? 1 : null">
                                <span class="field-err" x-text="err(`items.${i}.cutting_minutes`)"></span>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- C. Problem: Set Up & DT --}}
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <div class="text-[12px] font-semibold uppercase tracking-wider" style="color:var(--muted)">Problem (Set Up / Bongkar / DT)</div>
                        <span class="text-[12px]" style="color:var(--muted)" x-text="`Set up ${totalMinutes(it, 'setup')} mnt · Bongkar ${totalMinutes(it, 'bongkar')} mnt · DT ${totalMinutes(it, 'dt')} mnt`"></span>
                    </div>
                    {{-- jatah menit = waktu kerja − cutting time; lebih dari itu tidak bisa disimpan (isian tidak direset) --}}
                    <div class="rounded-xl px-3 py-2 text-[12.5px] flex items-start gap-2"
                         :style="overMinutes(it) || err(`items.${i}.problem_minutes`) ? 'background:color-mix(in srgb, var(--bad) 10%, transparent);color:var(--bad)' : 'background:var(--surface-2);color:var(--ink-2)'"
                         :data-error="overMinutes(it) || err(`items.${i}.problem_minutes`) ? 1 : null">
                        <x-icon name="info" :size="15" class="shrink-0 mt-0.5" />
                        <span x-text="overMinutes(it) ? budgetText(it) : (err(`items.${i}.problem_minutes`) || budgetText(it))"></span>
                    </div>
                    <template x-for="(p, j) in it.problems" :key="j">
                        <div class="rounded-xl p-3 space-y-2" style="background:var(--surface-2)">
                            <div class="flex items-center gap-2">
                                <span class="chip" :class="p.kind === 'dt' ? 'chip-warn' : 'chip-muted'" x-text="kindLabel(p.kind)"></span>
                                <span class="text-[12px]" style="color:var(--muted)" x-show="p.kind === 'dt'" x-text="'Opsi untuk ' + (cfg.machineTypes.find(t => t.key === (it.machine_type || 'umum'))?.label || '')"></span>
                                <button type="button" class="ml-auto text-[12.5px] font-medium" style="color:var(--bad)" @click="it.problems.splice(j, 1)">Hapus</button>
                            </div>
                            <div class="grid grid-cols-[minmax(0,1fr)_88px] gap-2">
                                @include('forms.production._option_search')
                                <input type="number" inputmode="numeric" min="0" class="input" x-model="p.minutes" :placeholder="minutesHint(it, p)" aria-label="Menit"
                                       :class="(err(`items.${i}.problems.${j}.minutes`) || overMinutes(it)) && 'is-invalid'" :data-error="err(`items.${i}.problems.${j}.minutes`) ? 1 : null">
                            </div>
                            <input class="input" x-show="p.option_id === 'other'" x-model="p.other_text" placeholder="Ketik keterangan lainnya" maxlength="150">
                            <span class="field-err" x-text="err(`items.${i}.problems.${j}.option_id`) || err(`items.${i}.problems.${j}.minutes`)"></span>
                        </div>
                    </template>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" class="btn" @click="addProblem(it, 'setup')"><x-icon name="plus" :size="16" />Set Up</button>
                        <button type="button" class="btn" @click="addProblem(it, 'bongkar')"><x-icon name="plus" :size="16" />Bongkar</button>
                        <button type="button" class="btn" @click="addProblem(it, 'dt')"><x-icon name="plus" :size="16" />Downtime</button>
                    </div>
                </div>

                {{-- D. Pencapaian produksi --}}
                <div class="space-y-3">
                    <div class="text-[12px] font-semibold uppercase tracking-wider" style="color:var(--muted)">Pencapaian produksi</div>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ([['std_output', 'Std output (pcs/jam) *', 'decimal'], ['act_output', 'Act output (pcs/jam) *', 'decimal'], ['ok_qty', 'Hasil OK (pcs) *', 'numeric'], ['second_grade_qty', 'Reject (pcs)', 'numeric'], ['nc_qty', 'Hasil NC (pcs)', 'numeric']] as [$k, $l, $mode])
                            <label class="block">
                                <span class="label">{{ $l }}</span>
                                <input type="number" inputmode="{{ $mode }}" min="0" step="any" class="input" x-model="it.{{ $k }}" :class="err(`items.${i}.{{ $k }}`) && 'is-invalid'" :data-error="err(`items.${i}.{{ $k }}`) ? 1 : null"
                                       @if (in_array($k, ['second_grade_qty', 'nc_qty'])) placeholder="Opsional" @endif>
                                <span class="field-err" x-text="err(`items.${i}.{{ $k }}`)"></span>
                            </label>
                        @endforeach
                        <label class="block" x-show="hasReject(it)" x-cloak>
                            <span class="label">Alasan reject *</span>
                            <input class="input" x-model="it.reject_reason" maxlength="255" placeholder="mis. Burry, panjang kurang"
                                   :class="err(`items.${i}.reject_reason`) && 'is-invalid'" :data-error="err(`items.${i}.reject_reason`) ? 1 : null">
                            <span class="field-err" x-text="err(`items.${i}.reject_reason`)"></span>
                        </label>
                        <label class="block">
                            <span class="label">Keterangan</span>
                            <input class="input" x-model="it.note" maxlength="255" placeholder="Opsional">
                        </label>
                    </div>
                </div>

                {{-- E. Foto --}}
                @include('forms._photos', ['row' => 'it'])

                <div class="flex justify-end" x-show="f.items.length > 1">
                    <button type="button" class="btn btn-danger" @click="removeItem(i)">Hapus PRO ini</button>
                </div>
            </div></div></div>
        </div>
    </template>

    <button type="button" class="btn w-full !h-12 !text-[14.5px]" style="border-style:dashed" @click="addItem()"><x-icon name="plus" :size="16" />Tambah PRO (ganti PRO / mesin lain)</button>

    <div class="sticky-bar -mx-4 lg:-mx-6 px-4 lg:px-6 py-3 flex flex-wrap items-center gap-x-3 gap-y-2">
        <span class="text-[13px] flex-1 min-w-[150px]" :style="message && 'color:var(--bad)'"
              x-text="message || (uploading ? `Mengunggah ${uploading} foto…` : `${f.items.length} PRO di form ini`)"></span>
        <a href="{{ $form ? route('forms.production.show', $form) : route('forms.production.index') }}" class="btn">Batal</a>
        <button type="button" class="btn btn-primary !px-6" :disabled="saving || uploading > 0" @click="save()">
            <span x-show="!saving">Simpan</span><span x-show="saving" x-cloak>Menyimpan…</span>
        </button>
    </div>
</div>
</x-layouts.app>
