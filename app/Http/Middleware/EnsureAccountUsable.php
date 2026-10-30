<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }
        if ($user?->must_change_password && ! $request->routeIs(
            'profile.edit', 'password.update', 'logout', 'password.confirm'
        )) {
            if ($request->expectsJson() && ! $request->headers->has('X-Inertia')) {
                abort(403, 'Debes cambiar tu contraseña antes de continuar.');
            }

            return redirect()->route('profile.edit')
                ->withErrors(['password' => 'Debes cambiar tu contraseña temporal para continuar.']);
        }

        return $next($request);
    }
}
