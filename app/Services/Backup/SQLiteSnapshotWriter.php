<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\DatabaseSnapshotWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

final class SQLiteSnapshotWriter implements DatabaseSnapshotWriter
{
    public function write(): string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('El escritor requiere SQLite.');
        }
        $directory = storage_path('app/private/backups/tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el temporal privado.');
        }
        $path = $directory.'/'.Str::uuid().'.sql';
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el temporal privado.');
        }
        @chmod($path, 0600);
        try {
            // Una única transacción mantiene la misma vista de esquema y filas, incluso con WAL.
            $connection->transaction(function () use ($connection, $stream): void {
                $pdo = $connection->getPdo();
                $schema = $pdo->query("SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL AND lower(substr(name,1,7)) <> 'sqlite_' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                $this->writeAll($stream, "-- FIEA SQLite snapshot\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
                foreach ($schema as $object) {
                    if ($object['type'] !== 'table') {
                        continue;
                    }
                    if (preg_match('/CREATE\s+VIRTUAL\s+TABLE/i', $object['sql'])) {
                        throw new RuntimeException('Las tablas virtuales requieren un escritor de respaldo específico.');
                    }
                    $this->writeAll($stream, $object['sql'].";\n");
                }
                foreach ($schema as $object) {
                    if ($object['type'] !== 'table') {
                        continue;
                    }
                    $table = $this->identifier($object['name']);
                    $columns = array_values(array_filter($pdo->query('PRAGMA table_xinfo('.$table.')')->fetchAll(PDO::FETCH_ASSOC), fn (array $c): bool => (int) $c['hidden'] === 0));
                    $names = array_map(fn (array $c): string => $this->identifier($c['name']), $columns);
                    $count = count($names);
                    $query = $pdo->query('SELECT '.implode(',', $names).','.implode(',', array_map(fn (string $c): string => 'typeof('.$c.')', $names)).' FROM '.$table);
                    while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
                        $values = [];
                        for ($i = 0; $i < $count; $i++) {
                            $values[] = $this->literal($row[$i], $row[$i + $count]);
                        }
                        $this->writeAll($stream, 'INSERT INTO '.$table.' ('.implode(',', $names).') VALUES ('.implode(',', $values).");\n");
                    }
                }
                $sequence = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='sqlite_sequence'")->fetchColumn();
                if ((int) $sequence > 0) {
                    $this->writeAll($stream, "DELETE FROM sqlite_sequence;\n");
                    foreach ($pdo->query('SELECT name,seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $this->writeAll($stream, 'INSERT INTO sqlite_sequence(name,seq) VALUES ('.$this->literal($row['name'], 'text').','.(string) (int) $row['seq'].");\n");
                    }
                }
                foreach (['view', 'index', 'trigger'] as $kind) {
                    foreach ($schema as $object) {
                        if ($object['type'] === $kind) {
                            $this->writeAll($stream, $object['sql'].";\n");
                        }
                    }
                }
                foreach (['user_version', 'application_id'] as $pragma) {
                    $this->writeAll($stream, 'PRAGMA '.$pragma.'='.(int) $pdo->query('PRAGMA '.$pragma)->fetchColumn().";\n");
                }
                $this->writeAll($stream, "COMMIT;\nPRAGMA foreign_keys=ON;\n");
                if (! fflush($stream)) {
                    throw new RuntimeException('No se pudo confirmar el temporal.');
                }
            });
        } catch (\Throwable $e) {
            fclose($stream);
            @unlink($path);
            throw $e;
        }
        fclose($stream);

        return $path;
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function literal(mixed $value, string $type): string
    {
        return match ($type) {
            'null' => 'NULL', 'integer' => (string) $value,
            'real' => is_finite((float) $value) ? sprintf('%.17h', (float) $value) : throw new RuntimeException('Se encontró un número no finito.'),
            'blob' => "X'".bin2hex((string) $value)."'",
            // La conversión hexadecimal conserva también ceros embebidos y comillas del texto.
            'text' => "CAST(X'".bin2hex((string) $value)."' AS TEXT)",
            default => throw new RuntimeException('Tipo SQLite no admitido.'),
        };
    }

    private function writeAll($stream, string $value): void
    {
        $offset = 0;
        $length = strlen($value);
        while ($offset < $length) {
            $written = fwrite($stream, substr($value, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Escritura incompleta del respaldo temporal.');
            }
            $offset += $written;
        }
    }
}
