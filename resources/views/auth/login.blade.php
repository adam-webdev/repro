<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · Production Monitoring · Departemen Repro</title>
    <x-favicons />
    <script>
        try { var t = localStorage.getItem('theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); document.documentElement.setAttribute('data-theme', t); } catch (e) {}
    </script>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-8">
<button type="button" class="icon-btn fixed top-3 right-3 z-10" title="Ganti mode terang / gelap" aria-label="Ganti mode terang / gelap"
        onclick="var h=document.documentElement,n=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',n);try{localStorage.setItem('theme',n)}catch(e){}">
    <x-icon name="moon" :size="18" class="theme-ico-light" /><x-icon name="sun" :size="18" class="theme-ico-dark" />
</button>

<main class="login-card w-full max-w-[1080px] grid lg:grid-cols-[1.15fr_1fr] overflow-hidden rounded-2xl border"
      style="background:var(--surface);border-color:var(--border)">

    {{-- Foto --}}
    <div class="relative h-48 sm:h-60 lg:h-auto lg:min-h-[620px]">
        <img src="{{ asset('brand/login-photo.webp') }}" alt="Jalur pipa di area produksi"
             class="absolute inset-0 w-full h-full object-cover" draggable="false">
        <div class="absolute inset-x-0 bottom-0 h-2/3" style="background:linear-gradient(to top, rgba(10,12,16,.72), rgba(10,12,16,0))"></div>
        <div class="absolute left-5 right-5 bottom-5 sm:left-8 sm:bottom-7 text-white">
            <div class="text-[11.5px] font-medium uppercase tracking-[.14em] opacity-80">Departemen Repro</div>
            <div class="mt-1.5 text-[19px] sm:text-[24px] font-semibold leading-snug tracking-tight max-w-[420px]">
                Monitoring hasil produksi, downtime, dan laporan karu.
            </div>
        </div>
    </div>

    {{-- Form --}}
    <div class="flex flex-col px-6 py-8 sm:px-12 sm:py-12">
        <x-brand-logo class="h-14 self-start" />

        <div class="my-auto pt-10 pb-4">
            <h1 class="text-[26px] font-semibold tracking-tight">Masuk</h1>
            <p class="text-[14px] mt-1.5" style="color:var(--muted)">Production Monitoring · Departemen Repro</p>

            <form method="POST" action="{{ url('/login') }}" id="login-form" class="mt-8 space-y-4">
                @csrf
                <div>
                    <label class="label !text-[13px]" for="login">Username / Kode Karu</label>
                    <div class="relative">
                        <x-icon name="user-round" :size="18" class="absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" style="color:var(--muted)" />
                        <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus autocapitalize="characters"
                               autocomplete="username" placeholder="mis. KA044" class="input w-full !h-12 !pl-11 !text-[15px]">
                    </div>
                </div>
                <div>
                    <label class="label !text-[13px]" for="password">Password</label>
                    <div class="relative">
                        <x-icon name="key-round" :size="18" class="absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" style="color:var(--muted)" />
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="input w-full !h-12 !pl-11 !pr-12 !text-[15px]">
                        <button type="button" class="absolute right-1.5 top-1/2 -translate-y-1/2 icon-btn" aria-label="Tampilkan password" title="Tampilkan / sembunyikan password"
                                onclick="var i=document.getElementById('password'),s=i.type==='password';i.type=s?'text':'password';this.querySelector('.pw-show').style.display=s?'none':'';this.querySelector('.pw-hide').style.display=s?'':'none'">
                            <x-icon name="eye" :size="18" class="pw-show" /><x-icon name="eye-off" :size="18" class="pw-hide" style="display:none" />
                        </button>
                    </div>
                </div>

                @error('login')
                    <div class="alert alert-crit text-[13px]">{{ $message }}</div>
                @enderror

                <label class="flex items-center gap-2 text-[13.5px] select-none" style="color:var(--ink-2)">
                    <input type="checkbox" name="remember" value="1" class="w-4 h-4"> Ingat saya di perangkat ini
                </label>

                <button id="login-submit" class="btn btn-primary w-full !h-12 !text-[15px] !mt-6 disabled:opacity-70 disabled:cursor-wait">
                    <svg class="login-spin animate-spin" style="display:none" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/>
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <span class="login-label">Masuk</span>
                </button>
            </form>
            <script>
                // Kunci tombol saat form dikirim agar tidak terkirim berkali-kali; dibuka lagi bila kembali lewat tombol Back.
                (function () {
                    var form = document.getElementById('login-form'), btn = document.getElementById('login-submit');
                    var setLoading = function (on) {
                        btn.disabled = on;
                        btn.setAttribute('aria-busy', on ? 'true' : 'false');
                        btn.querySelector('.login-spin').style.display = on ? '' : 'none';
                        btn.querySelector('.login-label').textContent = on ? 'Memproses…' : 'Masuk';
                    };
                    form.addEventListener('submit', function (e) {
                        if (btn.disabled) { e.preventDefault(); return; }
                        setLoading(true);
                    });
                    window.addEventListener('pageshow', function () { setLoading(false); });
                })();
            </script>

            @php
                $admin = config('production.admin_contact');
                $waText = rawurlencode('Halo Admin '.$admin['name'].', saya lupa password akun Production Monitoring Repro. Username / kode karu saya: ');
            @endphp
            <a href="https://wa.me/{{ $admin['whatsapp'] }}?text={{ $waText }}" target="_blank" rel="noopener"
               class="mt-6 flex items-center gap-3 rounded-xl border px-4 py-3 hover:bg-[var(--hover)] transition-colors" style="border-color:var(--border)">
                <span class="grid place-items-center w-9 h-9 rounded-full shrink-0" style="background:#25d366;color:#fff"><x-icon name="message-circle" :size="18" /></span>
                <span class="min-w-0">
                    <span class="block text-[13.5px] font-semibold">Lupa password?</span>
                    <span class="block text-[12.5px]" style="color:var(--muted)">Hubungi Admin Master {{ $admin['name'] }} via WhatsApp · {{ $admin['whatsapp_display'] }}</span>
                </span>
            </a>
        </div>

        <div class="pt-6 text-[12px]" style="color:var(--muted)">© {{ date('Y') }} SPINDO · Departemen Repro</div>
    </div>
</main>
</body>
</html>
