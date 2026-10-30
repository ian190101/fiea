<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\DatabaseSnapshotWriter;
use Illuminate\Support\Facades\DB;

final class ConfiguredSnapshotWriter implements DatabaseSnapshotWriter
{
    public function __construct(private readonly SQLiteSnapshotWriter $sqlite, private readonly MySqlSnapshotWriter $mysql) {}

    public function write(): string
    {
        $driver = DB::connection()->getDriverName();
        $configured = config('services.backup.snapshot_backend', 'auto');
        if ($configured !== 'auto' && $configured !== $driver) {
            throw new \RuntimeException('El escritor configurado no corresponde al motor activo.');
        }

        return match ($driver) {
            'sqlite' => $this->sqlite->write(), 'mysql' => $this->mysql->write(),
            default => throw new \RuntimeException('No hay un escritor consistente para el motor activo.'),
        };
    }
}
