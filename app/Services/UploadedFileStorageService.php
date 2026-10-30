<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\StorageFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UploadedFileStorageService
{
    public function __construct(private readonly FileStorageService $files) {}

    public function store(UploadedFile $file, string $directory, ?User $user = null): StorageFile
    {
        $mime = $file->getMimeType();
        $extensions = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg',
            'image/png' => 'png', 'image/webp' => 'webp',
        ];
        if (!$file->isValid() || !isset($extensions[$mime]) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Adjunta un PDF, JPG, PNG o WEBP válido de hasta 10 MB.',
            ]);
        }
        if (!preg_match('#\A(?:receipts/[1-9][0-9]*|branding/logos)\z#D', $directory)) {
            throw new \InvalidArgumentException('Directorio de almacenamiento no permitido.');
        }
        if (str_starts_with((string) $mime, 'image/')) {
            $size = @getimagesize($file->getRealPath());
            if (!$size || $size[0] > 10000 || $size[1] > 10000 || $size[0] * $size[1] > 25000000) {
                throw ValidationException::withMessages([
                    'file' => 'La imagen tiene dimensiones no admitidas.',
                ]);
            }
        }
        $disk = $this->files->activeDisk();
        $objectKey = $directory.'/'.Str::uuid().'.'.$extensions[$mime];
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo leer el archivo.');
        }
        try {
            if (!Storage::disk($disk)->put($objectKey, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('No se pudo guardar el archivo.');
            }
            return StorageFile::query()->create([
                'provider' => $this->files->providerForDisk($disk),
                'bucket' => $this->files->bucketForDisk($disk),
                'object_key' => $objectKey,
                'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'public_url' => null,
                'uploaded_by_id' => $user?->id,
            ]);
        } catch (\Throwable $exception) {
            try { Storage::disk($disk)->delete($objectKey); } catch (\Throwable) {}
            throw $exception;
        } finally {
            fclose($stream);
        }
    }
}
