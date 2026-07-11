<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class CreateDatabaseBackup extends Command
{
    protected $signature = 'fiea:backup-database';

    protected $description = 'Genera un backup SQL de la base de datos y lo guarda en el almacenamiento activo.';

    public function handle(DatabaseBackupService $backups): int
    {
        $backup = $backups->create();

        if ($backup->status === 'failed') {
            $this->error('No se pudo generar el backup: '.$backup->error_message);

            return self::FAILURE;
        }

        $this->info('Backup generado correctamente.');
        $this->line('Archivo: '.$backup->storageFile?->original_name);
        $this->line('Disco: '.$backup->disk);
        $this->line('Tamano: '.$backup->size_bytes.' bytes');

        return self::SUCCESS;
    }
}
