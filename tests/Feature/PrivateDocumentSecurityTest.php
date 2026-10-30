<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\FileStorageService;
use App\Services\UploadedFileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PrivateDocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_documento_con_extension_peligrosa_se_guarda_privado_segun_mime_real(): void
    {
        Storage::fake('local');
        config()->set('filesystems.default','local');
        $file=UploadedFile::fake()->create('factura.exe',1,'application/pdf');
        $stored=app(UploadedFileStorageService::class)->store($file,'receipts/1');
        self::assertStringEndsWith('.pdf',$stored->object_key);
        self::assertNull($stored->public_url);
        self::assertTrue(Storage::disk('local')->exists($stored->object_key));
    }

    public function test_svg_es_rechazado_y_r2_incompleto_falla_cerrado(): void
    {
        Storage::fake('local');
        config()->set('filesystems.default','local');
        $file=UploadedFile::fake()->createWithContent('logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        try {
            app(UploadedFileStorageService::class)->store($file,'branding/logos');
            self::fail('SVG no debe aceptarse.');
        } catch (ValidationException) {
            self::assertDatabaseCount('storage_files',0);
        }
        config()->set('filesystems.default','r2');
        $this->expectException(\RuntimeException::class);
        app(FileStorageService::class)->activeDisk();
    }

    public function test_nombre_no_controla_ruta_y_respuesta_tiene_correlacion(): void
    {
        Storage::fake('local');
        config()->set('filesystems.default','local');
        $file=UploadedFile::fake()->create('..\\carpeta\\nombre.pdf',1,'application/pdf');
        $stored=app(UploadedFileStorageService::class)->store($file,'receipts/1');
        self::assertStringNotContainsString('..',$stored->object_key);
        self::assertStringNotContainsString('\\',$stored->original_name);
        $this->get('/')->assertOk()->assertHeader('X-Request-ID');
    }
}
