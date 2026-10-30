<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;

final class AuthenticateCredentialSession extends AuthenticateSession
{
    public function handle($request, Closure $next): mixed
    {
        $guard = Auth::guard('web');
        // Una sesión heredada sin huella debe autenticarse otra vez; no se le atribuye la clave nueva.
        if ($request->hasSession() && $request->session()->has($guard->getName())
            && $guard->check() && ! $guard->viaRemember() && ! $request->session()->has('password_hash_web')) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            throw new AuthenticationException('Debes volver a iniciar sesión.', ['web']);
        }

        return parent::handle($request, $next);
    }
}
