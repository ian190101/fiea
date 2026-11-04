<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StorageFile;
use App\Models\SystemSetting;
use App\Services\BrandingAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BrandingLogoDatabaseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_binario_se_cachea_como_texto_y_conserva_los_bytes_originales(): void
    {
        Storage::fake('local');
        $image = file_get_contents(public_path('images/fiea-logo.png'));
        Storage::disk('local')->put('branding/prueba.png', $image);
        $file = StorageFile::query()->create(['provider' => 'local', 'object_key' => 'branding/prueba.png',
            'original_name' => 'prueba.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($image),
            'checksum' => hash('sha256', $image)]);
        SystemSetting::query()->create(['logo_file_id' => $file->id]);
        config(['cache.default' => 'database']);
        Cache::purge('database');
        // Se reproduce la rama con caché de producción; los demás tests usan la rama sin caché.
        $this->app['env'] = 'production';
        try {
            $service = app(BrandingAssetService::class);
            $uri = $service->logoDataUri();
            self::assertSame('data:image/png;base64,'.base64_encode($image), $uri);
            $row = DB::table('cache')->where('key', 'like', '%fiea:branding:logo-data%')->sole();
            $key = substr($row->key, strlen(Cache::store('database')->getStore()->getPrefix()));
            self::assertSame(base64_encode($image), Cache::get($key));
            self::assertSame(1, preg_match('//u', $row->value));
            Storage::disk('local')->put('branding/prueba.png', 'contenido cambiado');
            self::assertSame($uri, $service->logoDataUri());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
