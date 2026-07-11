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

        return Storage::disk($file->diskName())->response($file->object_key, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.($file->checksum ?: $file->id).'"',
        ]);
    }
}
