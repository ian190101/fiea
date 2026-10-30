<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\DatabaseSnapshotWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MySqlSnapshotWriter implements DatabaseSnapshotWriter
{
    public function write(): string
    {
        $connectionName = (string) config('database.default');
        $settings = (array) config('database.connections.'.$connectionName);
        if (($settings['driver'] ?? null) !== 'mysql'
            || ! in_array(config('services.backup.snapshot_backend', 'auto'), ['auto', 'mysql'], true)) {
            throw new RuntimeException('El driver de base de datos no admite este snapshot.');
        }
        $nonTransactional = DB::select("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'");
        if ($nonTransactional !== []) {
            throw new RuntimeException('Existen tablas sin engine transaccional.');
        }
        $dir = storage_path('app/private/backups/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio temporal privado.');
        }
        $id = (string) Str::uuid();
        $credentials = $dir.'/'.$id.'.cnf';
        $output = $dir.'/'.$id.'.sql';
        $handle = null;
        try {
            $lines = "[client]\n";
            foreach (['host', 'port', 'username', 'password'] as $key) {
                $value = (string) ($settings[$key] ?? '');
                if (str_contains($value, "\n") || str_contains($value, "\r")) {
                    throw new RuntimeException('Configuración de base de datos no válida.');
                }
                $option = $key === 'username' ? 'user' : $key;
                $lines .= $option.'="'.addcslashes($value, '\\"')."\"\n";
            }
            if (file_put_contents($credentials, $lines, LOCK_EX) === false) {
                throw new RuntimeException('No se pudieron preparar credenciales temporales.');
            }
            @chmod($credentials, 0600);
            if (DIRECTORY_SEPARATOR === '/' && (fileperms($credentials) & 0077) !== 0) {
                throw new RuntimeException('Permisos inseguros en credenciales temporales.');
            }
            $handle = fopen($output, 'wb');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir el dump temporal.');
            }
            @chmod($output, 0600);
            $process = new Process([
                (string) config('services.backup.mysqldump_binary', 'mysqldump'),
                '--defaults-extra-file='.$credentials,
                '--single-transaction', '--quick', '--hex-blob', '--routines', '--events',
                '--triggers', '--no-tablespaces', (string) ($settings['database'] ?? ''),
            ]);
            $process->setTimeout(3600);
            $process->run(function (string $type, string $data) use ($handle): void {
                if ($type === Process::OUT && fwrite($handle, $data) !== strlen($data)) {
                    throw new RuntimeException('No se pudo escribir el dump completo.');
                }
            });
            fflush($handle);
            fclose($handle);
            $handle = null;
            if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('El volcado de base de datos falló o quedó incompleto.');
            }

            return $output;
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($output);
            throw $exception;
        } finally {
            @unlink($credentials);
        }
    }
}
