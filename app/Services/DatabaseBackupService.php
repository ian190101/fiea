<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\StorageFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DatabaseBackupService
{
    public function __construct(
        private readonly FileStorageService $files,
        private readonly SystemNotificationService $notifications,
    ) {
    }

    public function create(?User $user = null): BackupRun
    {
        $backupRun = BackupRun::query()->create([
            'type' => 'database',
            'status' => 'running',
            'created_by_id' => $user?->id,
        ]);

        try {
            $contents = $this->dumpSql();
            $disk = $this->files->activeDisk();
            $fileName = 'fiea-database-'.now()->format('Ymd-His').'.sql';
            $objectKey = 'backups/database/'.$fileName;

            Storage::disk($disk)->put($objectKey, $contents);

            $storageFile = StorageFile::query()->create([
                'provider' => $this->files->providerForDisk($disk),
                'bucket' => $this->files->bucketForDisk($disk),
                'object_key' => $objectKey,
                'original_name' => $fileName,
                'mime_type' => 'application/sql',
                'size_bytes' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'public_url' => $this->files->publicUrl($disk, $objectKey),
                'uploaded_by_id' => $user?->id,
            ]);

            $backupRun->forceFill([
                'status' => 'completed',
                'disk' => $disk,
                'storage_file_id' => $storageFile->id,
                'size_bytes' => $storageFile->size_bytes,
                'checksum' => $storageFile->checksum,
                'completed_at' => now(),
            ])->save();
            $this->notifications->notifyPermission(
                permission: 'backups.view',
                type: 'backup_completed',
                severity: 'info',
                title: 'Backup generado',
                body: "El backup {$fileName} fue generado correctamente.",
                actionUrl: route('backups.index'),
                actor: $user,
                data: ['backup_run_id' => $backupRun->id],
            );
        } catch (Throwable $exception) {
            $backupRun->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();
            $this->notifications->notifyPermission(
                permission: 'backups.manage',
                type: 'backup_failed',
                severity: 'critical',
                title: 'Backup fallido',
                body: $exception->getMessage(),
                actionUrl: route('backups.index'),
                actor: $user,
                data: ['backup_run_id' => $backupRun->id],
            );
        }

        return $backupRun->fresh(['storageFile', 'createdBy']);
    }

    private function dumpSql(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();
        $tables = $this->tables($driver, $database);

        $lines = [
            '-- FIEA database backup',
            '-- Generated at: '.now()->toIso8601String(),
            '-- Connection: '.$driver,
            '',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        foreach ($tables as $table) {
            $lines[] = '-- Table: '.$table;
            $lines[] = $this->dropStatement($table);
            $lines[] = $this->createStatement($driver, $table);
            $lines[] = '';

            foreach ($connection->table($table)->cursor() as $row) {
                $values = get_object_vars($row);

                if ($values === []) {
                    continue;
                }

                $columns = collect(array_keys($values))
                    ->map(fn (string $column) => $this->identifier($column))
                    ->implode(', ');
                $quotedValues = collect($values)
                    ->map(fn ($value) => $this->literal($value))
                    ->implode(', ');

                $lines[] = 'INSERT INTO '.$this->identifier($table).' ('.$columns.') VALUES ('.$quotedValues.');';
            }

            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array<int, string>
     */
    private function tables(string $driver, string $database): array
    {
        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->sort()
                ->values()
                ->all();
        }

        return collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
            ->map(fn ($row) => (string) collect((array) $row)->first())
            ->filter(fn (string $table) => filled($table) && ! Str::startsWith($table, $database.'.'))
            ->sort()
            ->values()
            ->all();
    }

    private function createStatement(string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            $row = DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);

            return rtrim((string) $row->sql, ';').';';
        }

        $row = DB::selectOne('SHOW CREATE TABLE '.$this->identifier($table));
        $statement = collect((array) $row)->last();

        return rtrim((string) $statement, ';').';';
    }

    private function dropStatement(string $table): string
    {
        return 'DROP TABLE IF EXISTS '.$this->identifier($table).';';
    }

    private function identifier(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }

    private function literal($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }
}
