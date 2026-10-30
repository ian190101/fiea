<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AuthenticateCredentialSession;
use App\Http\Middleware\EnsureAccountUsable;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestCorrelation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $security = require __DIR__.'/../config/security.php';
        $middleware->trustProxies(
            at: $security['trusted_proxies'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->trustHosts(at: $security['trusted_hosts'], subdomains: false);

        $middleware->web(append: [
            AuthenticateCredentialSession::class,
            RequestCorrelation::class,
            AddSecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => EnsureUserHasPermission::class,
            'account.usable' => EnsureAccountUsable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $error, Request $request) {
            // Inertia conserva la redirección de validación; los demás fallos usan el mismo contrato JSON.
            $json = $request->expectsJson() || $request->headers->has('X-Inertia');
            if (! $request->expectsJson() && ($error instanceof ValidationException || $error instanceof AuthenticationException)) {
                return null;
            }

            $status = $error instanceof ValidationException ? 422
                : ($error instanceof AuthenticationException ? 401
                : ($error instanceof AuthorizationException ? 403
                : ($error instanceof HttpExceptionInterface
                    ? $error->getStatusCode() : 500)));
            $summary = match ($status) {
                401 => 'Debes iniciar sesión para continuar.',
                403 => 'No tienes permiso para realizar esta acción.',
                404 => 'El recurso solicitado no existe.',
                419 => 'La sesión ha caducado. Vuelve a iniciar sesión para continuar.',
                422 => 'Revisa los datos enviados.',
                default => $status >= 500 ? 'Ocurrió un error del servidor.' : 'No se pudo completar la solicitud.',
            };
            $problem = [
                'success' => false,
                'error' => $summary,
                'errorCode' => $error instanceof ValidationException ? 'VALIDATION_FAILED' : "HTTP_{$status}",
                'technicalMessage' => config('app.debug') ? $error->getMessage() : $summary,
                'fullError' => [
                    'endpoint' => '/'.$request->path(),
                    'method' => $request->method(),
                    'timestamp' => now()->toIso8601String(),
                    'requestId' => $request->attributes->get('correlation_id'),
                    'status' => $status,
                    ...($error instanceof ValidationException ? ['validation' => $error->errors()] : []),
                    ...(config('app.debug') ? ['exception' => get_class($error), 'message' => $error->getMessage(), 'trace' => $error->getTraceAsString()] : []),
                ],
                ...($error instanceof ValidationException ? ['errors' => $error->errors()] : []),
            ];

            return $json ? response()->json($problem, $status)
                : response()->view('errors.liquid', ['problem' => $problem, 'status' => $status], $status);
        });
        $exceptions->respond(function (Response $response) {
            $request = request();
            $correlationId = $request->attributes->get('correlation_id');
            if (is_string($correlationId)) {
                $response->headers->set('X-Request-ID', $correlationId);
            }
            if ($response->getStatusCode() === 409
                && $request->headers->has('X-Inertia')
                && ! $request->isMethod('GET')
                && ! $response->headers->has('X-Inertia-Location')) {
                return back(303)->withErrors([
                    'version' => 'La operación entró en conflicto con el estado actual. Conserva tus cambios, recarga los datos y revisa antes de reintentar.',
                ]);
            }

            return $response;
        });
    })->create();
