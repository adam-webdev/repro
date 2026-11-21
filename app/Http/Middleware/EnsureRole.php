<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian: ->middleware('role:produksi,karu'). Admin Master selalu boleh.
 * Sekaligus: akun nonaktif langsung dikeluarkan, akun dengan password awal diarahkan ke Profil.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['login' => 'Akun Anda dinonaktifkan. Hubungi Admin Master.']);
        }

        if ($user && $user->must_change_password && ! $request->routeIs('profile.*', 'logout')) {
            return redirect()->route('profile.edit');
        }

        if ($roles && ! $user?->hasRole(...$roles)) {
            abort(403, 'Menu ini tidak tersedia untuk role Anda.');
        }

        return $next($request);
    }
}
