<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // current_password is only checked when the user is actually
        // changing their password -- otherwise a stale/incorrect value
        // left by a password manager's autocomplete would block unrelated
        // profile edits (e.g. just fixing a typo in the fullname).
        $changingPassword = $request->filled('password');

        $validated = $request->validate([
            'fullname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            'current_password' => $changingPassword ? ['required', 'current_password'] : ['nullable'],
            'password' => ['nullable', 'confirmed', 'min:8'],
        ]);

        $user->fullname = $validated['fullname'];
        $user->email = $validated['email'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return back()->with('success', 'Perfil atualizado com sucesso!');
    }
}
