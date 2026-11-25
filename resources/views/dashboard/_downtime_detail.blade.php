{{-- Rincian Set Up / Downtime saat grafik diklik. Satu panel, tampil di dekat grafik yang diklik ($anchor). --}}
<div id="dt-detail-{{ $anchor }}" class="card" x-show="dt.open && dt.anchor === '{{ $anchor }}'" x-cloak>
    <div class="card-pad !pb-3 flex flex-wrap items-start gap-3 border-b" style="border-color:var(--border)">
        <span class="w-3 h-3 rounded-full mt-1.5 shrink-0" :style="'background:' + dt.color"></span>
        <div class="min-w-0 flex-1">
            <div class="card-title" x-text="dtTitle()"></div>
            <div class="card-sub" x-show="dt.data" x-text="dtSub()"></div>
        </div>
        <div class="seg" x-show="!dt.reason">
            <template x-for="c in [{ key: 'setup', name: 'Set Up' }, { key: 'downtime', name: 'Downtime' }]" :key="c.key">
                <button type="button" :class="dt.key === c.key && 'on'" @click="openDowntime(c.key, { bucket: dt.bucket, machine: dt.machine, anchor: dt.anchor, scroll: false })" x-text="c.name"></button>
            </template>
        </div>
        <button type="button" class="btn !h-8" x-show="dt.bucket" @click="openDowntime(dt.key, { machine: dt.machine, reason: dt.reason, anchor: dt.anchor, scroll: false })">Seluruh periode</button>
        <button type="button" class="icon-btn" @click="dt.open = false" aria-label="Tutup rincian"><x-icon name="x" :size="16" /></button>
    </div>
    <div class="card-pad">
        <div x-show="dt.loading" class="text-[13px]" style="color:var(--muted)">Memuat rincian…</div>
        <div x-show="!dt.loading && dt.data && !dt.data.reasons.length" class="text-[13px]" style="color:var(--muted)">Tidak ada catatan untuk periode ini.</div>
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5" x-show="!dt.loading && dt.data?.reasons.length">
            <div class="xl:col-span-2">
                {{-- klik pareto: kolom DT sudah dipilih, jadi yang dirinci adalah mesinnya --}}
                <template x-if="dt.reason">
                    <div>
                        <div class="label">Mesin yang mengalami <span x-text="dt.reason"></span></div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 mt-1">
                            <template x-for="m in dt.data?.machines || []" :key="m.code">
                                <div>
                                    <div class="flex items-baseline justify-between gap-3 text-[13px]">
                                        <span class="font-medium" x-text="m.code"></span>
                                        <span class="shrink-0 tabular-nums" style="color:var(--ink-2)">
                                            <b x-text="num(m.hours, 1) + ' j'"></b> · <span x-text="num(m.share, 1) + '%'"></span>
                                            <span style="color:var(--muted)" x-text="' · ' + m.occurrences + '×'"></span>
                                        </span>
                                    </div>
                                    <div class="h-1.5 rounded-full mt-1" style="background:var(--surface-2)">
                                        <div class="h-1.5 rounded-full" :style="`width:${Math.max(1, m.share)}%;background:${dt.color}`"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                <template x-if="!dt.reason">
                    <div>
                        <div class="label">Penyebab (keterangan kolom Excel)</div>
                        <div class="space-y-2 mt-1">
                            <template x-for="r in dt.data?.reasons || []" :key="r.name">
                                <div>
                                    <div class="flex items-baseline justify-between gap-3 text-[13px]">
                                        <span class="font-medium min-w-0 truncate" x-text="r.name" :title="r.name"></span>
                                        <span class="shrink-0 tabular-nums" style="color:var(--ink-2)">
                                            <b x-text="num(r.hours, 1) + ' j'"></b> · <span x-text="num(r.share, 1) + '%'"></span>
                                            <span style="color:var(--muted)" x-text="' · ' + r.occurrences + '×' + (dt.machine ? '' : ' · ' + r.machines + ' mesin')"></span>
                                        </span>
                                    </div>
                                    <div class="h-1.5 rounded-full mt-1" style="background:var(--surface-2)">
                                        <div class="h-1.5 rounded-full" :style="`width:${Math.max(1, r.share)}%;background:${dt.color}`"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <div class="space-y-4">
                <div x-show="!dt.reason && !dt.machine">
                    <div class="label">Mesin terbanyak</div>
                    <table class="tbl mt-1">
                        <tbody>
                            <template x-for="m in dt.data?.machines || []" :key="m.code">
                                <tr><td x-text="m.code"></td><td class="num" x-text="num(m.hours, 1) + ' j'"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div>
                    <div class="label">Catatan keterangan dari Excel</div>
                    <div class="text-[12.5px]" style="color:var(--muted)" x-show="!dt.data?.notes.length">Tidak ada catatan teks di kolom keterangan sheet DT.</div>
                    <div class="space-y-2 mt-1 max-h-[260px] overflow-y-auto pr-1">
                        <template x-for="(n, ni) in dt.data?.notes || []" :key="ni">
                            <div class="rounded-lg px-3 py-2 text-[12.5px]" style="background:var(--surface-2)">
                                <div style="color:var(--muted)" x-text="fmtDate(n.date) + ' · S' + n.shift + ' · ' + n.machine + (n.order_no ? ' · PRO ' + n.order_no : '') + ' · ' + n.minutes + ' mnt'"></div>
                                <div class="mt-0.5" x-text="n.note"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
