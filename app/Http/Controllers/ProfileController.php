<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()->load('employee')]);
    }

    /** Data diri yang boleh diubah sendiri: email & jenis kelamin. Nama, kode, ID card, jabatan diatur Admin Master. */
    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
        ]);
        $user->update(['email' => ($data['email'] ?? null) ?: null]);
        $user->employee?->update(['email' => ($data['email'] ?? null) ?: null, 'gender' => ($data['gender'] ?? null) ?: null]);

        return back()->with('success', 'Profil disimpan.');
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'Password lama salah.',
            'password.different' => 'Password baru harus berbeda dari password lama.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
        ], ['password' => 'password baru']);

        $user->forceFill(['password' => Hash::make($request->input('password')), 'must_change_password' => false])->save();

        return redirect()->route($user->homeRoute())->with('success', 'Password berhasil diganti.');
    }
}
