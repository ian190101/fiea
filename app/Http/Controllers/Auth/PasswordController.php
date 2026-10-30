<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\PasswordByteLength;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', new PasswordByteLength],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual ya no es válida.']);
            }
            $user->forceFill(['password' => Hash::make($validated['password']), 'must_change_password' => false,
                'remember_token' => Str::random(60)])->save();
            AuditLog::query()->create(['user_id' => $user->id, 'action' => 'password_changed', 'module' => 'profile',
                'auditable_type' => get_class($user), 'auditable_id' => $user->id, 'metadata' => ['password_changed' => true]]);
            Auth::guard('web')->setUser($user);
        });
        $request->session()->regenerate();

        return back();
    }
}
