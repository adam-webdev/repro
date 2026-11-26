{{-- Bar filter bersama: dashboard & monitoring karu. $karuMode = sembunyikan pilihan per hari/minggu/bulan. --}}
    <div class="card card-pad !py-3.5">
        <div class="grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap sm:gap-y-3">
            <div class="col-span-2 sm:col-span-1 min-w-0">
                <span class="label">Periode cepat</span>
                <div class="seg flex-wrap max-w-full">
                    <template x-for="p in presets" :key="p.key">
                        <button type="button" :class="preset === p.key && 'on'" @click="applyPreset(p.key)" x-text="p.label"></button>
                    </template>
                </div>
            </div>
            <div class="col-span-2 sm:col-span-1 grid grid-cols-2 gap-2 sm:flex">
                <div class="min-w-0">
                    <label class="label">Dari</label>
                    <input type="date" class="input w-full sm:w-[140px]" x-model="f.from" @change="preset='custom'; load()">
                </div>
                <div class="min-w-0">
                    <label class="label">Sampai</label>
                    <input type="date" class="input w-full sm:w-[140px]" x-model="f.to" @change="preset='custom'; load()">
                </div>
            </div>
            @unless ($karuMode ?? false)
            <div class="col-span-2 sm:col-span-1">
                <span class="label">Tampilkan per</span>
                <div class="seg">
                    <button type="button" :class="f.period==='day' && 'on'" @click="f.period='day'; load()">Hari</button>
                    <button type="button" :class="f.period==='week' && 'on'" @click="f.period='week'; load()">Minggu</button>
                    <button type="button" :class="f.period==='month' && 'on'" @click="f.period='month'; load()">Bulan</button>
                </div>
            </div>
            @endunless
            <div class="min-w-0">
                <label class="label">Shift</label>
                <select class="input w-full sm:w-[110px]" x-model="f.shift" @change="load()">
                    <option value="">Semua</option><option value="1">Shift 1</option><option value="2">Shift 2</option><option value="3">Shift 3</option>
                </select>
            </div>
            <div class="min-w-0">
                <label class="label">Gudang</label>
                <select class="input w-full sm:w-[140px]" x-model="f.building" @change="load()">
                    <option value="">Semua</option>
                    @foreach ($buildings as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
            </div>
            <div class="min-w-0">
                <label class="label">Area</label>
                <select class="input w-full sm:w-[120px]" x-model="f.area_id" @change="f.machine_ids = []; load()">
                    <option value="">Semua</option>
                    @foreach ($areas as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                </select>
            </div>
            {{-- Mesin: centang beberapa untuk dibandingkan; grafik & tabel hanya menampilkan mesin yang dicentang --}}
            <div class="relative min-w-0" x-data="{ open: false, q: '', before: '' }"
                 @click.outside="if (open) { open = false; if (before !== JSON.stringify(f.machine_ids)) load() }">
                <span class="label">Mesin</span>
                <button type="button" class="input w-full sm:w-[170px] flex items-center gap-2 text-left" :aria-expanded="open"
                        @click="open = !open; if (open) { before = JSON.stringify(f.machine_ids); q = ''; $nextTick(() => $refs.mq.focus()) } else if (before !== JSON.stringify(f.machine_ids)) load()">
                    <span class="flex-1 min-w-0 truncate" x-text="machineLabel()"></span>
                    <x-icon name="chevron-down" :size="15" style="color:var(--muted)" />
                </button>
                <div x-show="open" x-cloak class="combo-pop w-[min(300px,calc(100vw-32px))] !right-auto sm:!min-w-[260px] max-sm:fixed max-sm:!left-4 max-sm:!right-4 max-sm:!top-auto max-sm:bottom-4 max-sm:!w-auto">
                    <div class="p-2 border-b" style="border-color:var(--border)">
                        <input x-ref="mq" type="search" class="input w-full" x-model="q" placeholder="Cari mesin…" aria-label="Cari mesin">
                    </div>
                    <div class="flex items-center justify-between px-3 pt-2 text-[12px]">
                        <span style="color:var(--muted)" x-text="f.machine_ids.length ? f.machine_ids.length + ' mesin dicentang' : 'Semua mesin'"></span>
                        <button type="button" class="font-medium underline underline-offset-2" x-show="f.machine_ids.length" @click="f.machine_ids = []">Kosongkan</button>
                    </div>
                    <div class="combo-list">
                        <template x-for="m in machineOptions().filter(m => !q || m.code.toLowerCase().includes(q.toLowerCase()))" :key="m.id">
                            <label class="tick !mx-0 !px-2">
                                <input type="checkbox" :checked="f.machine_ids.includes(String(m.id))" @change="toggleMachine(m.id)">
                                <span class="flex-1" x-text="m.code"></span>
                                <span class="text-[11.5px]" style="color:var(--muted)" x-text="areaName(m.area_id)"></span>
                            </label>
                        </template>
                    </div>
                    <div class="p-2 border-t" style="border-color:var(--border)">
                        <button type="button" class="btn btn-primary w-full" @click="open = false; if (before !== JSON.stringify(f.machine_ids)) load()">Terapkan</button>
                    </div>
                </div>
            </div>
            <button class="btn col-span-2 sm:col-span-1" @click="reset()" x-show="isFiltered()" x-cloak>Reset</button>
            <div class="col-span-2 sm:ml-auto text-[12.5px] sm:text-[12px] leading-relaxed sm:text-right" style="color:var(--muted)">
                {{ ($karuMode ?? false) ? 'Form tersedia:' : 'Data tersedia:' }} {{ $range->min ? \Carbon\Carbon::parse($range->min)->translatedFormat('d M Y') : '-' }} – {{ $range->max ? \Carbon\Carbon::parse($range->max)->translatedFormat('d M Y') : '-' }}<br>
                <span x-show="loading" class="inline-flex items-center gap-1">Memuat…</span>
                <span x-show="!loading && d && d.prev_range">Dibanding <span x-text="d && fmtRange(d.prev_range)"></span></span>
            </div>
        </div>
    </div>

