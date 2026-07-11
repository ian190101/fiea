<?php

namespace App\Services;

use App\Models\StorageFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadedFileStorageService
{
    public function __construct(private readonly FileStorageService $files)
    {
    }

    public function store(UploadedFile $file, string $directory, ?User $user = null): StorageFile
    {
        $disk = $this->files->activeDisk();
        $extension = $file->getClientOriginalExtension();
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $fileName = $safeName.'-'.Str::uuid().($extension ? '.'.$extension : '');
        $objectKey = trim($directory, '/').'/'.$fileName;
        $contents = file_get_contents($file->getRealPath());

        Storage::disk($disk)->put($objectKey, $contents);

        return StorageFile::query()->create([
            'provider' => $this->files->providerForDisk($disk),
            'bucket' => $this->files->bucketForDisk($disk),
            'object_key' => $objectKey,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'public_url' => $this->files->publicUrl($disk, $objectKey),
            'uploaded_by_id' => $user?->id,
        ]);
    }
}
