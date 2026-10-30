<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Contracts\DatabaseSnapshotWriter;
use App\Services\DatabaseBackupService;
use App\Services\SystemNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWriter(): void
    {
        $this->app->instance(DatabaseSnapshotWriter::class,
            new class implements DatabaseSnapshotWriter {
                public function write(): string
                {
                    $path=tempnam(sys_get_temp_dir(),'fiea-snapshot-');
                    file_put_contents($path,"CREATE TABLE verificada(id INTEGER);\n");
                    return $path;
                }
            });
        $notifications=\Mockery::mock(SystemNotificationService::class);
        $notifications->shouldReceive('notifyPermission')->zeroOrMoreTimes();
        $this->app->instance(SystemNotificationService::class,$notifications);
        Storage::fake('local');
        config()->set('filesystems.default','local');
    }

    public function test_respaldo_cifrado_se_verifica_antes_de_marcar_completado(): void
    {
        $this->fakeWriter();
        $key=random_bytes(32);
        config()->set('services.backup.encryption_key',base64_encode($key));
        $run=app(DatabaseBackupService::class)->create();
        self::assertSame('completed',$run->status,$run->error_message??'');
        self::assertNull($run->storageFile->public_url);
        $cipher=Storage::disk('local')->get($run->storageFile->object_key);
        self::assertStringStartsWith('FIEA-BACKUP-1',$cipher);
        self::assertStringNotContainsString('CREATE TABLE verificada',$cipher);
        self::assertSame(hash('sha256',$cipher),$run->checksum);
        $prefix="FIEA-BACKUP-1\nv1\n";
        $offset=strlen($prefix);
        $header=substr($cipher,$offset,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);
        $offset+=strlen($header);
        $restored='';
        while ($offset<strlen($cipher)) {
            $size=unpack('N',substr($cipher,$offset,4))[1];
            $offset+=4;
            [$part,$tag]=sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,substr($cipher,$offset,$size));
            $offset+=$size;
            $restored.=$part;
        }
        self::assertSame("CREATE TABLE verificada(id INTEGER);\n",$restored);
    }

    public function test_sin_clave_independiente_falla_sin_archivo_publicado(): void
    {
        $this->fakeWriter();
        config()->set('services.backup.encryption_key',null);
        $run=app(DatabaseBackupService::class)->create();
        self::assertSame('failed',$run->status);
        self::assertNull($run->storage_file_id);
        self::assertDatabaseCount('storage_files',0);
    }

    public function test_fallo_de_notificacion_conserva_respaldo_verificado(): void
    {
        $this->fakeWriter();
        config()->set('services.backup.encryption_key', base64_encode(random_bytes(32)));
        $notifications = \Mockery::mock(SystemNotificationService::class);
        $notifications->shouldReceive('notifyPermission')
            ->once()->andThrow(new \RuntimeException('Canal de avisos no disponible.'));
        $this->app->instance(SystemNotificationService::class, $notifications);

        $run = app(DatabaseBackupService::class)->create();

        self::assertSame('completed', $run->status);
        self::assertNotNull($run->storage_file_id);
        self::assertTrue(Storage::disk('local')->exists($run->storageFile->object_key));
    }
}
