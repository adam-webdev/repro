<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} · Production Monitoring · Repro</title>
    <x-favicons />
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', t);
                if (localStorage.getItem('sidebar') === 'collapsed') document.documentElement.setAttribute('data-sidebar', 'collapsed');
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" x-data="{ nav: false }" @keydown.window.ctrl.b.prevent="window.toggleSidebar()">
@php
    $u = auth()->user();
    $sections = [
        [null, [['dashboard', 'Dashboard', 'layout-dashboard', 'dashboard', true]]],
        ['Form karu', [
            ['forms.monitoring', 'Monitoring karu', 'activity', 'forms.monitoring*', $u->hasRole('karu', 'produksi')],
            ['forms.production.index', 'Form rekapan', 'clipboard-list', 'forms.production.*', $u->hasRole('karu', 'produksi')],
            ['forms.evaluation.index', 'Form daily supervisor', 'clipboard-check', 'forms.evaluation.*', $u->hasRole('karu', 'produksi')],
        ]],
        ['Data produksi', [
            ['imports.index', 'Import data', 'upload', 'imports.*', $u->hasRole('produksi')],
            ['reports.index', 'Tabel data', 'sheet', 'reports.*|orders.*', $u->hasRole('produksi')],
        ]],
        ['Admin', [
            ['employees.index', 'Karyawan & akun', 'users', 'employees.*', $u->isMaster()],
            ['activity.index', 'Log aktivitas', 'history', 'activity.*', $u->isMaster()],
        ]],
    ];
    $initials = collect(explode(' ', $u->name))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('');
@endphp

<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <div x-show="nav" x-cloak @click="nav=false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>
    <aside class="sidebar fixed inset-y-0 left-0 z-40 flex flex-col border-r -translate-x-full lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen"
           style="background:var(--sidebar);border-color:var(--border)" :class="nav && '!translate-x-0'">
        <div class="flex items-center gap-2 h-14 px-3 shrink-0">
            <a href="{{ route('dashboard') }}" class="sb-expanded-only flex items-center gap-2.5 min-w-0 flex-1 px-1">
                <x-brand-logo variant="mark" class="h-[26px]" />
                <span class="leading-tight min-w-0">
                    <span class="block text-[13.5px] font-semibold truncate">Production Monitoring</span>
                    <span class="block text-[11.5px] truncate" style="color:var(--muted)">Departemen Repro</span>
                </span>
            </a>
            <button type="button" class="icon-btn hidden lg:inline-grid mx-auto" onclick="window.toggleSidebar()" title="Buka / tutup sidebar (Ctrl+B)" aria-label="Buka / tutup sidebar">
                <x-icon name="panel-left" :size="18" />
            </button>
            <button type="button" class="icon-btn lg:hidden" @click="nav=false" aria-label="Tutup menu"><x-icon name="x" :size="18" /></button>
        </div>

        <nav class="flex-1 px-2.5 pb-3 overflow-y-auto overflow-x-hidden">
            @foreach ($sections as [$heading, $links])
                @php $visible = array_filter($links, fn ($l) => $l[4]); @endphp
                @if ($visible)
                    @if ($heading)<div class="nav-heading">{{ $heading }}</div>@endif
                    <div class="space-y-0.5">
                        @foreach ($visible as [$route, $label, $icon, $pattern])
                            <a href="{{ route($route) }}" title="{{ $label }}" class="nav-link {{ request()->routeIs(...explode('|', $pattern)) ? 'active' : '' }}">
                                <x-icon :name="$icon" :size="18" />
                                <span class="sb-label truncate">{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="p-2.5 border-t relative" style="border-color:var(--border)" x-data="{ open: false }">
            <div x-show="open" x-cloak @click.outside="open=false" x-transition.opacity.duration.100ms
                 class="absolute bottom-full left-2.5 mb-1 w-56 card p-1 z-50" style="box-shadow:0 12px 32px rgba(0,0,0,.14)">
                <div class="px-2.5 py-2 border-b mb-1" style="border-color:var(--border)">
                    <div class="text-[13px] font-medium truncate">{{ $u->name }}</div>
                    <div class="text-[12px] truncate" style="color:var(--muted)">{{ $u->username }} · {{ $u->roleLabel() }}</div>
                </div>
                <a href="{{ route('profile.edit') }}" class="nav-link !h-9"><x-icon name="user-round" :size="17" />Profil & password</a>
                <button type="button" class="nav-link !h-9 w-full" onclick="window.toggleTheme()">
                    <x-icon name="moon" :size="17" class="theme-ico-light" /><x-icon name="sun" :size="17" class="theme-ico-dark" /><span class="theme-ico-light">Mode gelap</span><span class="theme-ico-dark">Mode terang</span>
                </button>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="nav-link !h-9 w-full"><x-icon name="log-out" :size="17" />Keluar</button>
                </form>
            </div>
            <button @click="open=!open" class="nav-link w-full !h-11" title="{{ $u->name }}">
                <span class="grid place-items-center w-7 h-7 rounded-full text-[11.5px] font-semibold shrink-0" style="background:var(--active);color:var(--ink)">{{ mb_strtoupper($initials) }}</span>
                <span class="sb-label text-left leading-tight min-w-0">
                    <span class="block text-[13px] font-medium truncate" style="color:var(--ink)">{{ $u->name }}</span>
                    <span class="block text-[11.5px] truncate" style="color:var(--muted)">{{ $u->roleLabel() }}{{ $u->employee?->code ? ' · '.$u->employee->code : '' }}</span>
                </span>
            </button>
        </div>
    </aside>

    {{-- Konten --}}
    <div class="app-content flex-1 min-w-0 flex flex-col">
        <header class="sticky top-0 z-20 flex items-center gap-2 h-14 px-3 lg:px-6 border-b backdrop-blur"
                style="background:color-mix(in srgb, var(--page) 85%, transparent);border-color:var(--border)">
            <button class="icon-btn lg:hidden" @click="nav=true" aria-label="Menu"><x-icon name="menu" :size="19" /></button>
            <h1 class="text-[15px] font-semibold truncate">{{ $title ?? 'Dashboard' }}</h1>
            <div class="ml-auto flex items-center gap-2">
                {{ $actions ?? '' }}
                <button type="button" class="icon-btn" onclick="window.toggleTheme()" title="Ganti mode terang / gelap" aria-label="Ganti mode terang / gelap">
                    <x-icon name="moon" :size="18" class="theme-ico-light" /><x-icon name="sun" :size="18" class="theme-ico-dark" />
                </button>
            </div>
        </header>

        <main class="flex-1 px-4 lg:px-6 py-5 w-full max-w-[1600px] mx-auto">
            @if (session('success'))
                <x-alert type="ok" class="mb-4">{{ session('success') }}</x-alert>
            @endif
            @if (session('error'))
                <x-alert type="crit" class="mb-4">{{ session('error') }}</x-alert>
            @endif
            @if ($errors->any())
                <x-alert type="crit" class="mb-4">
                    {{ $errors->first() }}@if ($errors->count() > 1) <span style="color:var(--muted)">(+{{ $errors->count() - 1 }} lainnya)</span>@endif
                </x-alert>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Kartu karyawan: muncul saat nama pengguna diklik di halaman mana pun --}}
<div x-data="employeeCard" @employee-card.window="open($event.detail)" x-show="show" x-cloak
     class="fixed inset-0 z-50 grid place-items-center p-4" style="background:rgba(0,0,0,.35)"
     @click.self="show=false" @keydown.escape.window="show=false">
    <div class="card w-full max-w-sm overflow-hidden" style="box-shadow:0 20px 50px rgba(0,0,0,.25)">
        <div x-show="loading" class="p-6"><div class="skeleton h-5 w-40 mb-3"></div><div class="skeleton h-4 w-56"></div></div>
        <template x-if="e && !loading">
            <div>
                <div class="card-pad flex items-start gap-3">
                    <div class="grid place-items-center w-11 h-11 rounded-full text-[14px] font-semibold shrink-0" style="background:var(--active)" x-text="initials()"></div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[15.5px] font-semibold leading-tight" x-text="e.name"></div>
                        <div class="text-[13px] mt-0.5" style="color:var(--ink-2)" x-text="[e.position, e.code].filter(Boolean).join(' · ') || '-'"></div>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            <span class="chip chip-muted" x-show="e.role" x-text="e.role"></span>
                            <span class="chip" :class="e.active ? 'chip-ok' : 'chip-crit'" x-show="e.username" x-text="e.active ? 'Aktif' : 'Nonaktif'"></span>
                            <span class="chip chip-crit" x-show="e.deleted">Dihapus</span>
                        </div>
                    </div>
                    <button class="icon-btn" @click="show=false" aria-label="Tutup"><x-icon name="x" :size="17" /></button>
                </div>
                <dl class="px-5 pb-4 grid grid-cols-2 gap-x-4 gap-y-2.5 text-[13px]">
                    <div><dt style="color:var(--muted)">Username</dt><dd x-text="e.username || '-'"></dd></div>
                    <div><dt style="color:var(--muted)">ID Card</dt><dd x-text="e.id_card || '-'"></dd></div>
                    <div><dt style="color:var(--muted)">Jenis kelamin</dt><dd x-text="e.gender || '-'"></dd></div>
                    <div><dt style="color:var(--muted)">Login terakhir</dt><dd x-text="e.last_login || '-'"></dd></div>
                    <div class="col-span-2"><dt style="color:var(--muted)">Email</dt><dd x-text="e.email || '-'"></dd></div>
                </dl>
                <div class="px-5 py-3 border-t grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12.5px]" style="border-color:var(--border)" x-show="Object.keys(e.stats || {}).length">
                    <template x-for="[k, v] in Object.entries(e.stats || {})" :key="k">
                        <div class="flex justify-between gap-2"><span style="color:var(--muted)" x-text="k"></span><b class="tabular-nums" x-text="v"></b></div>
                    </template>
                </div>
                <div class="px-5 py-3 border-t text-right" style="border-color:var(--border)" x-show="e.edit_url">
                    <a :href="e.edit_url" class="btn !h-8">Kelola karyawan</a>
                </div>
            </div>
        </template>
    </div>
</div>
</body>
</html>
