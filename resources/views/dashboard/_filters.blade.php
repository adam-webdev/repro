{{-- Bar filter bersama: dashboard & monitoring karu. $karuMode = sembunyikan pilihan per hari/minggu/bulan. --}}
    <div class="card card-pad !py-3.5">
        <div class="flex flex-wrap items-end gap-x-3 gap-y-3">
            <div>
                <span class="label">Periode cepat</span>
                <div class="seg flex-wrap">
                    <template x-for="p in presets" :key="p.key">
                        <button type="button" :class="preset === p.key && 'on'" @click="applyPreset(p.key)" x-text="p.label"></button>
                    </template>
                </div>
            </div>
            <div class="flex gap-2">
                <div>
                    <label class="label">Dari</label>
                    <input type="date" class="input w-[140px]" x-model="f.from" @change="preset='custom'; load()">
                </div>
                <div>
                    <label class="label">Sampai</label>
                    <input type="date" class="input w-[140px]" x-model="f.to" @change="preset='custom'; load()">
                </div>
            </div>
            @unless ($karuMode ?? false)
            <div>
                <span class="label">Tampilkan per</span>
                <div class="seg">
                    <button type="button" :class="f.period==='day' && 'on'" @click="f.period='day'; load()">Hari</button>
                    <button type="button" :class="f.period==='week' && 'on'" @click="f.period='week'; load()">Minggu</button>
                    <button type="button" :class="f.period==='month' && 'on'" @click="f.period='month'; load()">Bulan</button>
                </div>
            </div>
            @endunless
            <div>
                <label class="label">Shift</label>
                <select class="input w-[110px]" x-model="f.shift" @change="load()">
                    <option value="">Semua</option><option value="1">Shift 1</option><option value="2">Shift 2</option><option value="3">Shift 3</option>
                </select>
            </div>
            <div>
                <label class="label">Gedung</label>
                <select class="input w-[140px]" x-model="f.building" @change="load()">
                    <option value="">Semua</option>
                    @foreach ($buildings as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label">Area</label>
                <select class="input w-[120px]" x-model="f.area_id" @change="f.machine_id=''; load()">
                    <option value="">Semua</option>
                    @foreach ($areas as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label">Mesin</label>
                <select class="input w-[130px]" x-model="f.machine_id" @change="load()">
                    <option value="">Semua</option>
                    <template x-for="m in machineOptions()" :key="m.id"><option :value="m.id" x-text="m.code"></option></template>
                </select>
            </div>
            <button class="btn" @click="reset()" x-show="isFiltered()" x-cloak>Reset</button>
            <div class="ml-auto text-[12px] leading-relaxed text-right" style="color:var(--muted)">
                {{ ($karuMode ?? false) ? 'Form tersedia:' : 'Data tersedia:' }} {{ $range->min ? \Carbon\Carbon::parse($range->min)->translatedFormat('d M Y') : '-' }} – {{ $range->max ? \Carbon\Carbon::parse($range->max)->translatedFormat('d M Y') : '-' }}<br>
                <span x-show="loading" class="inline-flex items-center gap-1">Memuat…</span>
                <span x-show="!loading && d && d.prev_range">Dibanding <span x-text="d && fmtRange(d.prev_range)"></span></span>
            </div>
        </div>
    </div>

