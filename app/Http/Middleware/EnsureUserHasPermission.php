<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        // Solo local/testing pueden arrancar sin RBAC sembrado; en produccion debe fallar cerrado.
        if (!$this->rbacIsSeeded() && app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        if ($user->hasAnyPermission($permissions)) {
            return $next($request);
        }

        abort(403, 'No tienes permiso para acceder a este modulo.');
    }

    private function rbacIsSeeded(): bool
    {
        if (app()->environment('testing')) {
            return Permission::query()->exists();
        }

        return Cache::remember('fiea:rbac:seeded', now()->addMinute(), fn () => Permission::query()->exists());
    }
}
