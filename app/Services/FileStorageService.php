<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class FileStorageService
{
    public function activeDisk(): string
    {
        return $this->r2IsReady() ? 'r2' : 'local';
    }

    public function providerForDisk(string $disk): string
    {
        return $disk === 'r2' ? 'cloudflare_r2' : 'local';
    }

    public function bucketForDisk(string $disk): ?string
    {
        return $disk === 'r2' ? config('filesystems.disks.r2.bucket') : null;
    }

    public function publicUrl(string $disk, string $objectKey): ?string
    {
        if ($disk === 'r2' && filled(config('filesystems.disks.r2.url'))) {
            return rtrim((string) config('filesystems.disks.r2.url'), '/').'/'.ltrim($objectKey, '/');
        }

        if ($disk === 'public') {
            return Storage::disk('public')->url($objectKey);
        }

        return null;
    }

    /**
     * @return array{configured_disk: string, active_disk: string, provider: string, bucket: string|null, endpoint_configured: bool, public_url_configured: bool, adapter_installed: bool, ready: bool}
     */
    public function status(): array
    {
        $activeDisk = $this->activeDisk();

        return [
            'configured_disk' => (string) config('filesystems.default'),
            'active_disk' => $activeDisk,
            'provider' => $this->providerForDisk($activeDisk),
            'bucket' => config('filesystems.disks.r2.bucket'),
            'endpoint_configured' => filled(config('filesystems.disks.r2.endpoint')),
            'public_url_configured' => filled(config('filesystems.disks.r2.url')),
            'adapter_installed' => $this->s3AdapterInstalled(),
            'ready' => $activeDisk === 'r2',
        ];
    }

    private function r2IsReady(): bool
    {
        return config('filesystems.default') === 'r2'
            && $this->s3AdapterInstalled()
            && filled(config('filesystems.disks.r2.key'))
            && filled(config('filesystems.disks.r2.secret'))
            && filled(config('filesystems.disks.r2.bucket'))
            && filled(config('filesystems.disks.r2.endpoint'));
    }

    private function s3AdapterInstalled(): bool
    {
        return class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
    }
}
