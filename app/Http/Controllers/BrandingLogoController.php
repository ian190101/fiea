<?php

namespace App\Http\Controllers;

use App\Models\StorageFile;
use App\Services\BrandingAssetService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingLogoController extends Controller
{
    public function show(BrandingAssetService $brandingAssets): StreamedResponse
    {
        $file = $brandingAssets->logoFile();
        abort_unless($file instanceof StorageFile, 404);
        $version = request()->query('v');
        if ($version !== null) {
            abort_unless(is_string($version) && hash_equals((string) ($file->checksum ?: $file->id), $version), 404);
        }

        return Storage::disk($file->diskName())->response($file->object_key, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'image/png',
            'Cache-Control' => $version !== null ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate',
            'ETag' => '"'.($file->checksum ?: $file->id).'"',
        ]);
    }
}
