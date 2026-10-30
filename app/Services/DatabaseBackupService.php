<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DatabaseSnapshotWriter;
use App\Models\BackupRun;
use App\Models\StorageFile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DatabaseBackupService
{
    public function __construct(
        private readonly FileStorageService $files,
        private readonly SystemNotificationService $notifications,
        private readonly DatabaseSnapshotWriter $writer,
    ) {}

    public function create(?User $user = null): BackupRun
    {
        $run = BackupRun::query()->create([
            'type' => 'database', 'status' => 'running', 'created_by_id' => $user?->id,
        ]);
        $lock = null;
        $plain = null;
        $encrypted = null;
        $objectKey = null;
        $disk = null;
        try {
            $lock = Cache::lock('fiea:database-backup', 7200);
            if (! $lock->get()) {
                throw new RuntimeException('Ya hay un respaldo en curso.');
            }
            $plain = $this->writer->write();
            $encrypted = $this->encrypt($plain);
            $disk = $this->files->activeDisk();
            $name = 'fiea-database-'.now()->format('Ymd-His').'-'.Str::uuid().'.fiea';
            $objectKey = 'backups/database/'.$name;
            $stream = fopen($encrypted, 'rb');
            if ($stream === false) {
                throw new RuntimeException('No se pudo leer el respaldo cifrado.');
            }
            try {
                if (! Storage::disk($disk)->put($objectKey, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('No se pudo guardar el respaldo.');
                }
            } finally {
                fclose($stream);
            }
            $remote = Storage::disk($disk)->readStream($objectKey);
            if ($remote === false) {
                throw new RuntimeException('No se pudo verificar el respaldo.');
            }
            try {
                $remoteHash = hash_init('sha256');
                $remoteSize = hash_update_stream($remoteHash, $remote);
                $checksum = hash_final($remoteHash);
            } finally {
                fclose($remote);
            }
            if ($remoteSize !== filesize($encrypted) || ! hash_equals(hash_file('sha256', $encrypted), $checksum)) {
                throw new RuntimeException('El respaldo almacenado no superó la verificación.');
            }
            DB::transaction(function () use ($disk, $objectKey, $name, $remoteSize, $checksum, $user, $run): void {
                $file = StorageFile::query()->create([
                    'provider' => $this->files->providerForDisk($disk),
                    'bucket' => $this->files->bucketForDisk($disk),
                    'object_key' => $objectKey, 'original_name' => $name,
                    'mime_type' => 'application/octet-stream', 'size_bytes' => $remoteSize,
                    'checksum' => $checksum, 'public_url' => null,
                    'uploaded_by_id' => $user?->id,
                ]);
                $run->forceFill([
                    'status' => 'completed', 'disk' => $disk, 'storage_file_id' => $file->id,
                    'size_bytes' => $remoteSize, 'checksum' => $checksum, 'completed_at' => now(),
                ])->save();
            });
            // Un fallo al avisar no invalida un respaldo ya verificado y confirmado.
            try {
                $this->notifications->notifyPermission(
                    permission: 'backups.view', type: 'backup_completed', severity: 'info',
                    title: 'Backup generado', body: 'El respaldo cifrado fue verificado.',
                    actionUrl: route('backups.index'), actor: $user,
                    data: ['backup_run_id' => $run->id],
                );
            } catch (Throwable $notificationError) {
                $this->reportSafely($notificationError);
            }
        } catch (Throwable $exception) {
            $reference = (string) Str::uuid();
            $this->reportSafely($exception);
            if ($disk !== null && $objectKey !== null) {
                try {
                    Storage::disk($disk)->delete($objectKey);
                } catch (Throwable $cleanup) {
                    $this->reportSafely($cleanup);
                }
            }
            $run->forceFill(['status' => 'failed', 'error_message' => $reference,
                'completed_at' => now()])->save();
            try {
                $this->notifications->notifyPermission(
                    permission: 'backups.manage', type: 'backup_failed', severity: 'critical',
                    title: 'Backup fallido', body: 'Referencia: '.$reference,
                    actionUrl: route('backups.index'), actor: $user,
                    data: ['backup_run_id' => $run->id],
                );
            } catch (Throwable $notificationError) {
                try {
                    report($notificationError);
                } catch (Throwable) {
                }
            }
        } finally {
            if ($plain !== null) {
                @unlink($plain);
            }
            if ($encrypted !== null) {
                @unlink($encrypted);
            }
            try {
                $lock?->release();
            } catch (Throwable) {
            }
        }

        return $run->fresh(['storageFile', 'createdBy']);
    }

    private function encrypt(string $plain): string
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('La extensión sodium es obligatoria para respaldos.');
        }
        $encoded = (string) config('services.backup.encryption_key');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('La clave de respaldo independiente no está configurada.');
        }
        $version = (string) config('services.backup.key_version', 'v1');
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/D', $version)) {
            throw new RuntimeException('Versión de clave inválida.');
        }
        $target = $plain.'.fiea';
        $source = false;
        $output = false;
        try {
            $source = fopen($plain, 'rb');
            $output = fopen($target, 'xb');
            if ($source === false || $output === false) {
                throw new RuntimeException('No se pudo abrir el respaldo temporal.');
            }
            @chmod($target, 0600);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($output, "FIEA-BACKUP-1\n".$version."\n".$header);
            while (! feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('No se pudo leer el dump.');
                }
                if ($chunk === '') {
                    break;
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk);
                $this->writeAll($output, pack('N', strlen($cipher)).$cipher);
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '',
                '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->writeAll($output, pack('N', strlen($final)).$final);
            if (! fflush($output)) {
                throw new RuntimeException('No se pudo confirmar el respaldo cifrado.');
            }

            return $target;
        } catch (Throwable $exception) {
            @unlink($target);
            throw $exception;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Escritura incompleta del respaldo cifrado.');
            }
            $offset += $written;
        }
    }

    private function reportSafely(Throwable $error): void
    {
        try {
            report($error);
        } catch (Throwable) { /* El fallo del canal secundario no cambia el resultado financiero. */
        }
    }
}
