{{-- Filter area & mesin khusus satu grafik ($chart); tanggal, shift & gudang tetap ikut filter utama. --}}
<div class="flex flex-wrap items-center gap-2 mt-2.5">
    <span class="text-[12px]" style="color:var(--muted)">Filter grafik ini:</span>
    <select class="input !h-8 !py-0 w-[130px] text-[12.5px]" x-model="cf.{{ $chart }}.area_id" @change="cf.{{ $chart }}.machine_id = ''; loadChart('{{ $chart }}')" aria-label="Area grafik">
        <option value="">Semua area</option>
        <template x-for="a in cfg.areas" :key="a.id"><option :value="a.id" x-text="a.name"></option></template>
    </select>
    <select class="input !h-8 !py-0 w-[140px] text-[12.5px]" x-model="cf.{{ $chart }}.machine_id" @change="loadChart('{{ $chart }}')" aria-label="Mesin grafik">
        <option value="">Semua mesin</option>
        <template x-for="m in chartMachineOptions('{{ $chart }}')" :key="m.id"><option :value="m.id" x-text="m.code"></option></template>
    </select>
</div>
