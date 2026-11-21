<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    /** Masuk dengan username (mis. KA044 / admin) atau email. */
    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:100', 'password' => 'required|string']);
        $field = str_contains($data['login'], '@') ? 'email' : 'username';

        if (! Auth::attempt([$field => trim($data['login']), 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['login' => 'Username atau password salah, atau akun nonaktif.'])->onlyInput('login');
        }
        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($user->must_change_password) {
            return redirect()->route('profile.edit');
        }

        return redirect()->intended(route($user->homeRoute()));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
