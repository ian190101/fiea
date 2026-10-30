<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\AdministrativeContinuity;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'security' => Inertia::defer(fn () => [
                'last_login_at' => $request->user()->last_login_at?->toIso8601String(),
                'last_login_ip' => $request->user()->last_login_ip,
                'current_ip' => $request->ip(),
                'current_user_agent' => substr((string) $request->userAgent(), 0, 500),
            ], 'profile'),
            'activity' => Inertia::defer(fn () => AuditLog::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->limit(12)
                ->get(['id', 'action', 'module', 'ip_address', 'user_agent', 'created_at'])
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'module' => $log->module,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]), 'profile'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $before = $user->only(['name', 'username', 'email']);
            $user->fill($request->validated());
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();
            AuditLog::query()->create(['user_id' => $user->id, 'action' => 'profile_updated', 'module' => 'profile',
                'auditable_type' => get_class($user), 'auditable_id' => $user->id,
                'metadata' => ['before' => $before, 'after' => $user->only(['name', 'username', 'email'])]]);
        });

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($user): void {
                $continuity = app(AdministrativeContinuity::class);
                $continuity->lock();
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $continuity->ensure($locked, false, false, []);
                $locked->delete();
                AuditLog::query()->create(['user_id' => null, 'action' => 'profile_deleted', 'module' => 'profile',
                    'auditable_type' => get_class($locked), 'auditable_id' => $locked->id, 'metadata' => ['actor_id' => $locked->id]]);
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['password' => 'La cuenta conserva referencias y no se puede eliminar. Solicita su desactivación.']);
            }
            throw $e;
        }
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
