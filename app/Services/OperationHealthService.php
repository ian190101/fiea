<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OperationHealthService
{
    public function __construct(private readonly FileStorageService $files)
    {
    }

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}>}
     */
    public function status(): array
    {
        if (! app()->environment('testing')) {
            return Cache::remember('fiea:operations:health', now()->addSeconds(30), fn () => $this->freshStatus());
        }

        return $this->freshStatus();
    }

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}>}
     */
    private function freshStatus(): array
    {
        $checks = [
            $this->database(),
            $this->storage(),
            $this->cache(),
            $this->queue(),
            $this->scheduler(),
            $this->configuration(),
        ];

        return [
            'overall' => $this->overall($checks),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function database(): array
    {
        try {
            $result = DB::select('select 1 as ok');
            $requiredTables = [
                'users',
                'roles',
                'permissions',
                'projects',
                'trip_phases',
                'invoices',
                'storage_files',
            ];

            $missingTables = collect($requiredTables)
                ->reject(fn (string $table) => Schema::hasTable($table))
                ->values()
                ->all();

            return [
                'name' => 'Base de datos',
                'status' => empty($missingTables) && ((int) ($result[0]->ok ?? 0) === 1) ? 'ok' : 'warning',
                'detail' => empty($missingTables)
                    ? 'Conexion activa y tablas principales disponibles.'
                    : 'Faltan tablas principales: '.implode(', ', $missingTables).'.',
                'meta' => [
                    'connection' => config('database.default'),
                    'missing_tables' => $missingTables,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Base de datos', 'No se pudo conectar con la base de datos.', $exception);
        }
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function storage(): array
    {
        $status = $this->files->status();
        $disk = $this->files->activeDisk();
        $probePath = 'health/.probe-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.txt';

        try {
            Storage::disk($disk)->put($probePath, 'ok');
            $exists = Storage::disk($disk)->exists($probePath);
            Storage::disk($disk)->delete($probePath);

            return [
                'name' => 'Almacenamiento',
                'status' => $exists ? ($status['ready'] ? 'ok' : 'warning') : 'failed',
                'detail' => $exists
                    ? ($status['ready'] ? 'Cloudflare R2 esta activo.' : 'Usando almacenamiento local como fallback.')
                    : 'No se pudo confirmar escritura en el disco activo.',
                'meta' => $status,
            ];
        } catch (Throwable $exception) {
            return $this->failed('Almacenamiento', 'No se pudo escribir en el disco activo.', $exception, $status);
        }
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function cache(): array
    {
        $key = 'fiea:health:'.bin2hex(random_bytes(4));

        try {
            Cache::put($key, 'ok', now()->addMinute());
            $value = Cache::get($key);
            Cache::forget($key);

            return [
                'name' => 'Cache',
                'status' => $value === 'ok' ? 'ok' : 'warning',
                'detail' => $value === 'ok' ? 'Cache disponible.' : 'Cache respondio con un valor inesperado.',
                'meta' => [
                    'driver' => config('cache.default'),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Cache', 'No se pudo escribir o leer cache.', $exception);
        }
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function queue(): array
    {
        $connection = (string) config('queue.default');
        $isProduction = app()->environment('production');
        $isSync = $connection === 'sync';
        $databaseQueueMissing = $connection === 'database' && ! Schema::hasTable('jobs');

        if ($databaseQueueMissing) {
            return [
                'name' => 'Colas',
                'status' => 'failed',
                'detail' => 'La cola database esta configurada, pero la tabla jobs no existe.',
                'meta' => [
                    'connection' => $connection,
                    'jobs_table' => false,
                    'environment' => app()->environment(),
                ],
            ];
        }

        return [
            'name' => 'Colas',
            'status' => $isProduction && $isSync ? 'warning' : 'ok',
            'detail' => $isProduction && $isSync
                ? 'En produccion conviene usar database, redis o supervisor para trabajos en segundo plano.'
                : 'Conexion de cola configurada.',
            'meta' => [
                'connection' => $connection,
                'jobs_table' => $connection === 'database' ? Schema::hasTable('jobs') : null,
                'environment' => app()->environment(),
            ],
        ];
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function scheduler(): array
    {
        $commands = Artisan::all();
        $hasAutomation = array_key_exists('fiea:email-automation', $commands);

        return [
            'name' => 'Scheduler',
            'status' => $hasAutomation ? 'ok' : 'warning',
            'detail' => $hasAutomation
                ? 'Comando de automatizacion de correos registrado. En hosting debe ejecutarse schedule:run cada minuto.'
                : 'No se encontro el comando de automatizacion de correos.',
            'meta' => [
                'email_automation_command' => $hasAutomation,
            ],
        ];
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function configuration(): array
    {
        $missing = collect([
            'APP_KEY' => filled(config('app.key')),
            'APP_URL' => filled(config('app.url')),
            'MAIL_MAILER' => filled(config('mail.default')),
        ])->filter(fn (bool $ready) => ! $ready)->keys()->values()->all();
        $warnings = [];

        if (app()->environment('production') && (bool) config('app.debug')) {
            $warnings[] = 'APP_DEBUG debe estar en false.';
        }

        if (app()->environment('production') && ! (bool) config('session.secure')) {
            $warnings[] = 'SESSION_SECURE_COOKIE debe estar en true.';
        }

        if (app()->environment('production') && ! (bool) config('session.encrypt')) {
            $warnings[] = 'SESSION_ENCRYPT debe estar en true.';
        }

        return [
            'name' => 'Configuracion',
            'status' => empty($missing) && empty($warnings) ? 'ok' : 'warning',
            'detail' => $this->configurationDetail($missing, $warnings),
            'meta' => [
                'missing' => $missing,
                'warnings' => $warnings,
                'app_env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'session_secure_cookie' => (bool) config('session.secure'),
                'session_encrypt' => (bool) config('session.encrypt'),
            ],
        ];
    }

    /**
     * @param array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}> $checks
     */
    private function overall(array $checks): string
    {
        if (collect($checks)->contains(fn (array $check) => $check['status'] === 'failed')) {
            return 'failed';
        }

        if (collect($checks)->contains(fn (array $check) => $check['status'] === 'warning')) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function failed(string $name, string $detail, Throwable $exception, array $meta = []): array
    {
        return [
            'name' => $name,
            'status' => 'failed',
            'detail' => $detail,
            'meta' => [
                ...$meta,
                'error' => $exception->getMessage(),
            ],
        ];
    }

    /**
     * @param array<int, string> $missing
     * @param array<int, string> $warnings
     */
    private function configurationDetail(array $missing, array $warnings): string
    {
        if (! empty($missing)) {
            return 'Faltan variables criticas: '.implode(', ', $missing).'.';
        }

        if (! empty($warnings)) {
            return implode(' ', $warnings);
        }

        return 'Variables criticas configuradas.';
    }
}
