<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OperationHealthService
{
    public function __construct(private readonly FileStorageService $files) {}

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}>}
     */
    public function status(bool $fresh = false): array
    {
        if (! $fresh && ! app()->environment('testing')) {
            try {
                return Cache::remember('fiea:operations:health', now()->addSeconds(30), fn () => $this->freshStatus());
            } catch (Throwable $e) {
                try {
                    report($e);
                } catch (Throwable) {
                }
            }
        }

        return $this->freshStatus();
    }

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}>}
     */
    private function freshStatus(): array
    {
        $checks = [];
        foreach (['database' => 'Base de datos', 'rbac' => 'RBAC', 'accounts' => 'Contraseñas de demostración',
            'accountMiddleware' => 'Ciclo de cuenta', 'financialSchema' => 'Esquema financiero B02', 'runtime' => 'Dependencias y perímetro',
            'storage' => 'Almacenamiento', 'cache' => 'Cache', 'queue' => 'Colas', 'scheduler' => 'Scheduler',
            'failedJobs' => 'Trabajos fallidos', 'backups' => 'Respaldo y restauración', 'configuration' => 'Configuracion'] as $method => $name) {
            try {
                $checks[] = $this->{$method}();
            } catch (Throwable $e) {
                $checks[] = $this->failed($name, 'No se pudo completar este chequeo; los demás siguen disponibles.', $e);
            }
        }

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
                'billing_cases',
                'budget_lines',
                'budget_cells',
                'invoice_sequences',
                'treasury_payments',
                'credit_accounts',
                'outbox_events',
            ];

            $missingTables = collect($requiredTables)
                ->reject(fn (string $table) => Schema::hasTable($table))
                ->values()
                ->all();

            return [
                'name' => 'Base de datos',
                'status' => empty($missingTables) && ((int) ($result[0]->ok ?? 0) === 1) ? 'ok' : 'failed',
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

    private function rbac(): array
    {
        try {
            $ready = Role::query()->exists()
                && Permission::query()->exists()
                && Role::query()->whereHas('permissions')->exists();

            return [
                'name' => 'RBAC', 'status' => $ready ? 'ok' : 'failed',
                'detail' => $ready ? 'Roles y permisos asignados.' : 'RBAC no tiene permisos asignados a roles.',
                'meta' => [],
            ];
        } catch (Throwable $exception) {
            return $this->failed('RBAC', 'No se pudo comprobar la asignación de permisos.', $exception);
        }
    }

    private function accounts(): array
    {
        try {
            $found = false;
            $demoPasswords = ['password', 'password123', 'admin123', 'password-123', '12345678'];
            User::query()->where('is_active', true)->select(['id', 'password'])
                ->chunkById(100, function ($users) use (&$found, $demoPasswords): bool {
                    foreach ($users as $user) {
                        foreach ($demoPasswords as $candidate) {
                            if (Hash::check($candidate, (string) $user->password)) {
                                $found = true;

                                return false;
                            }
                        }
                    }

                    return true;
                });

            return [
                'name' => 'Contraseñas de demostración', 'status' => $found ? 'failed' : 'ok',
                'detail' => $found ? 'Existe una cuenta activa con una contraseña de demostración conocida.'
                    : 'No se detectaron contraseñas de demostración conocidas en cuentas activas.',
                'meta' => [],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Contraseñas de demostración', 'No se pudo verificar la seguridad de las cuentas.', $exception);
        }
    }

    private function accountMiddleware(): array
    {
        try {
            $route = Route::getRoutes()->getByName('dashboard');
            $middleware = $route?->gatherMiddleware() ?? [];
            $ready = in_array('account.usable', $middleware, true);

            return [
                'name' => 'Ciclo de cuenta', 'status' => $ready ? 'ok' : 'failed',
                'detail' => $ready ? 'El acceso autenticado comprueba el estado de la cuenta.'
                    : 'La ruta principal no aplica account.usable para must_change_password.',
                'meta' => [],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Ciclo de cuenta', 'No se pudo verificar el middleware de cuenta.', $exception);
        }
    }

    private function financialSchema(): array
    {
        try {
            $required = [
                ['users', 'scope_all'], ['trip_phases', 'version'],
                ['invoices', 'billing_case_id'], ['invoices', 'snapshot'],
                ['budget_cells', 'quantity'], ['email_logs', 'delivery_key'],
                ['users', 'username_normalized'], ['budget_line_versions', 'is_captured'], ['receipts', 'budget_line_id'],
            ];
            $missing = array_values(array_filter($required,
                fn (array $item): bool => ! Schema::hasTable($item[0]) || ! Schema::hasColumn($item[0], $item[1])));

            return [
                'name' => 'Esquema financiero B02', 'status' => $missing === [] ? 'ok' : 'failed',
                'detail' => $missing === [] ? 'Columnas financieras y de seguridad disponibles.'
                    : 'Faltan columnas del esquema financiero B02.',
                'meta' => ['missing' => array_map(fn (array $item): string => implode('.', $item), $missing)],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Esquema financiero B02', 'No se pudo verificar el esquema financiero.', $exception);
        }
    }

    private function runtime(): array
    {
        $problems = [];
        if (! extension_loaded('bcmath')) {
            $problems[] = 'Falta la extensión bcmath.';
        }
        if (app()->environment('production')) {
            if ((bool) config('app.debug')) {
                $problems[] = 'APP_DEBUG debe estar desactivado.';
            }
            if (config('security.trusted_proxies', []) === []) {
                $problems[] = 'Faltan proxies confiables.';
            }
            $hosts = (array) config('security.trusted_hosts', []);
            if ($hosts === [] || in_array('*', $hosts, true)) {
                $problems[] = 'Faltan hosts confiables concretos.';
            }
            if (! (bool) config('session.secure') || ! (bool) config('session.http_only')
                || ! (bool) config('session.encrypt') || config('session.same_site') !== 'lax') {
                $problems[] = 'Las cookies de sesión no tienen configuración de producción segura.';
            }
        }

        return [
            'name' => 'Dependencias y perímetro', 'status' => $problems === [] ? 'ok' : 'failed',
            'detail' => $problems === [] ? 'Dependencias y perímetro configurados.' : implode(' ', $problems),
            'meta' => [],
        ];
    }

    /**
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function storage(): array
    {
        try {
            $disk = $this->files->activeDisk();
            $probePath = 'health/.probe-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.txt';
            $contents = bin2hex(random_bytes(16));
            $adapter = Storage::disk($disk);
            $deleted = false;
            try {
                $written = $adapter->put($probePath, $contents);
                $exists = $written && $adapter->exists($probePath) && hash_equals($contents, (string) $adapter->get($probePath));
            } finally {
                $deleted = $adapter->delete($probePath) && ! $adapter->exists($probePath);
            }
            $exists = $exists && $deleted;

            $privateLocal = $disk !== 'local' || ! (bool) config('filesystems.disks.local.serve', false);

            return [
                'name' => 'Almacenamiento',
                'status' => $exists && $privateLocal ? 'ok' : 'failed',
                'detail' => $exists && $privateLocal
                    ? 'El disco privado activo permite escritura y lectura.'
                    : 'No se pudo confirmar almacenamiento privado en el disco activo.',
                'meta' => ['disk' => $disk],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Almacenamiento', 'No se pudo comprobar el disco privado activo.', $exception);
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
        $queueConfig = (array) config('queue.connections.'.$connection, []);
        $isSync = ($queueConfig['driver'] ?? $connection) === 'sync';
        $databaseQueue = ($queueConfig['driver'] ?? $connection) === 'database';
        $jobsTable = (string) ($queueConfig['table'] ?? 'jobs');
        $schema = $databaseQueue ? DB::connection($queueConfig['connection'] ?? null)->getSchemaBuilder() : null;
        $databaseQueueMissing = $databaseQueue && ! $schema->hasTable($jobsTable);

        if ($databaseQueueMissing) {
            return [
                'name' => 'Colas',
                'status' => 'failed',
                'detail' => 'La tabla configurada para la cola database no existe.',
                'meta' => [
                    'connection' => $connection,
                    'jobs_table' => false,
                    'environment' => app()->environment(),
                ],
            ];
        }

        $heartbeat = ! $isProduction || $this->recentHeartbeat('fiea:queue:heartbeat');

        return [
            'name' => 'Colas',
            'status' => $isProduction && $isSync ? 'failed' : ($heartbeat ? 'ok' : 'warning'),
            'detail' => $isProduction && $isSync
                ? 'La cola sync no es apta para producción.'
                : ($heartbeat ? 'Conexión de cola configurada y latido presente.' : 'No se recibió latido reciente del worker.'),
            'meta' => [
                'connection' => $connection,
                'jobs_table' => $databaseQueue ? $schema->hasTable($jobsTable) : null,
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
        $hasAutomation = array_key_exists('fiea:email-automation', $commands)
            && array_key_exists('fiea:dispatch-outbox', $commands);
        $heartbeat = ! app()->environment('production') || $this->recentHeartbeat('fiea:scheduler:heartbeat');

        return [
            'name' => 'Scheduler',
            'status' => ! $hasAutomation ? 'failed' : ($heartbeat ? 'ok' : 'warning'),
            'detail' => ! $hasAutomation ? 'Faltan comandos de automatización u outbox.'
                : ($heartbeat ? 'Comandos y latido del scheduler presentes.' : 'No se recibió latido reciente del scheduler.'),
            'meta' => [
                'email_automation_command' => $hasAutomation,
            ],
        ];
    }

    private function failedJobs(): array
    {
        try {
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $connection = DB::connection(config('queue.failed.database'));
            $exists = $connection->getSchemaBuilder()->hasTable($table);
            $count = $exists ? $connection->table($table)->count() : 0;
            $ready = ! app()->environment('production') || $exists;

            return [
                'name' => 'Trabajos fallidos',
                'status' => ! $ready ? 'failed' : ($count > 0 ? 'warning' : 'ok'),
                'detail' => ! $ready ? 'No existe la tabla de trabajos fallidos.'
                    : ($count > 0 ? 'Hay trabajos fallidos pendientes de revisión.' : 'No hay trabajos fallidos pendientes.'),
                'meta' => ['count' => $count, 'table' => $table],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Trabajos fallidos', 'No se pudo revisar la cola de fallos.', $exception);
        }
    }

    private function backups(): array
    {
        if (! app()->environment('production')) {
            return ['name' => 'Respaldo y restauración', 'status' => 'ok',
                'detail' => 'Comprobación operativa aplicable en producción.', 'meta' => []];
        }
        try {
            if (! Schema::hasTable('backup_runs')) {
                return ['name' => 'Respaldo y restauración', 'status' => 'failed',
                    'detail' => 'No existe el registro de respaldos.', 'meta' => []];
            }
            $hours = max(1, (int) config('services.backup.max_age_hours', 24));
            $run = BackupRun::query()->with('storageFile')->where('status', 'completed')->orderByDesc('completed_at')->first();
            $last = $run?->completed_at;
            $fresh = $last !== null && Carbon::parse($last)->greaterThan(now()->subHours($hours));
            $file = $run?->storageFile;
            $available = $file && $run->disk === $file->diskName() && Storage::disk($run->disk)->exists($file->object_key)
                && (int) Storage::disk($run->disk)->size($file->object_key) === (int) $run->size_bytes
                && (int) $file->size_bytes === (int) $run->size_bytes
                && hash_equals((string) $run->checksum, (string) $file->checksum);
            $fresh = $fresh && $available;
            $restoredAt = Cache::get('fiea:backup:restore_verified_at');
            $restored = is_string($restoredAt)
                && Carbon::parse($restoredAt)->greaterThan(now()->subDays(30));

            return [
                'name' => 'Respaldo y restauración', 'status' => $fresh && $restored ? 'ok' : 'warning',
                'detail' => $fresh && $restored ? 'Respaldo reciente y ensayo de restauración registrados.'
                    : 'Falta respaldo reciente o ensayo de restauración verificado.',
                'meta' => ['max_age_hours' => $hours],
            ];
        } catch (Throwable $exception) {
            return $this->failed('Respaldo y restauración', 'No se pudo verificar el plan de restauración.', $exception);
        }
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
        if (app()->environment('production') && ! (bool) config('session.http_only')) {
            $warnings[] = 'SESSION_HTTP_ONLY debe estar en true.';
        }
        if (app()->environment('production') && config('session.same_site') !== 'lax') {
            $warnings[] = 'SESSION_SAME_SITE debe ser lax.';
        }
        if (app()->environment('production') && config('security.trusted_proxies', []) === []) {
            $warnings[] = 'TRUSTED_PROXIES debe definir los rangos del balanceador.';
        }
        if (app()->environment('production') && (config('security.trusted_hosts', []) === []
            || in_array('*', config('security.trusted_hosts', []), true))) {
            $warnings[] = 'TRUSTED_HOST_PATTERNS debe definir hosts concretos.';
        }
        if (app()->environment('production') && blank(config('services.backup.encryption_key'))) {
            $warnings[] = 'La clave independiente de respaldos debe estar configurada.';
        }
        if (app()->environment('production')
            && (int) config('services.resend.idempotency_window_seconds', 0) <= 60) {
            $warnings[] = 'Configura la ventana confirmada de idempotencia del proveedor de correo.';
        }

        if (app()->environment('production') && config('queue.default') === 'sync') {
            $warnings[] = 'QUEUE_CONNECTION debe usar database o redis.';
        }

        if (app()->environment('production')
            && config('services.transactional_mail.provider') === 'resend'
            && blank(config('services.resend.key'))) {
            $warnings[] = 'RESEND_API_KEY debe estar configurado para correo transaccional.';
        }

        return [
            'name' => 'Configuracion',
            'status' => ! empty($missing) ? 'failed' : (empty($warnings) ? 'ok' : 'warning'),
            'detail' => $this->configurationDetail($missing, $warnings),
            'meta' => [
                'missing' => $missing,
                'warnings' => $warnings,
                'app_env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'session_secure_cookie' => (bool) config('session.secure'),
                'session_encrypt' => (bool) config('session.encrypt'),
                'filesystem_disk' => config('filesystems.default'),
                'queue_connection' => config('queue.default'),
                'mail_provider' => config('services.transactional_mail.provider'),
            ],
        ];
    }

    /**
     * @param  array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}>  $checks
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
     * @param  array<string, mixed>  $meta
     * @return array{name: string, status: string, detail: string, meta: array<string, mixed>}
     */
    private function failed(string $name, string $detail, Throwable $exception, array $meta = []): array
    {
        $reference = (string) Str::uuid();
        try {
            report($exception);
        } catch (Throwable) { /* Se conserva el diagnóstico aunque falle el registro secundario. */
        }

        return [
            'name' => $name,
            'status' => 'failed',
            'detail' => $detail,
            'meta' => [
                ...$meta,
                'error_reference' => $reference,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $warnings
     */
    private function recentHeartbeat(string $key): bool
    {
        $value = Cache::get($key);
        if (! is_string($value)) {
            return false;
        }
        try {
            $at = Carbon::parse($value);

            return $at->greaterThanOrEqualTo(now()->subMinutes(5)) && $at->lessThanOrEqualTo(now()->addSeconds(30));
        } catch (Throwable) {
            return false;
        }
    }

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
