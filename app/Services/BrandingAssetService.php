<?php

namespace App\Services;

use App\Models\StorageFile;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BrandingAssetService
{
    private const SETTINGS_CACHE_KEY = 'fiea:branding:settings';
    private const LOGO_DATA_CACHE_PREFIX = 'fiea:branding:logo-data:';

    /**
     * @return array{primary: string, secondary: string, accent: string}
     */
    public function colors(): array
    {
        $settings = $this->settings();

        return [
            'primary' => $settings?->primary_color ?? '#2563eb',
            'secondary' => $settings?->secondary_color ?? '#0f766e',
            'accent' => $settings?->accent_color ?? '#f59e0b',
        ];
    }

    public function logoFile(): ?StorageFile
    {
        return $this->settings()?->logoFile;
    }

    public function logoUrl(): ?string
    {
        $file = $this->logoFile();

        if (! $file instanceof StorageFile) {
            return null;
        }

        return route('branding.logo', ['v' => $file->updated_at?->timestamp ?? $file->id]);
    }

    public function logoDataUri(): ?string
    {
        $file = $this->logoFile();

        if (! $file instanceof StorageFile) {
            return null;
        }

        $disk = $file->diskName();

        if (! Storage::disk($disk)->exists($file->object_key)) {
            return null;
        }

        $contents = $this->remember("{$file->id}:{$file->checksum}:{$file->updated_at?->timestamp}", 3600, function () use ($disk, $file) {
            return Storage::disk($disk)->get($file->object_key);
        });
        $mimeType = $file->mime_type ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    public function flushCache(): void
    {
        Cache::forget(self::SETTINGS_CACHE_KEY);
    }

    private function settings(): ?SystemSetting
    {
        if (! Schema::hasTable('system_settings')) {
            return null;
        }

        return $this->remember(self::SETTINGS_CACHE_KEY, 300, fn () => SystemSetting::query()
            ->with('logoFile')
            ->first());
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        $normalizedKey = str_starts_with($key, 'fiea:')
            ? $key
            : self::LOGO_DATA_CACHE_PREFIX.$key;

        return Cache::remember($normalizedKey, now()->addSeconds($seconds), $callback);
    }

    /**
     * @return array{primary: string, secondary: string, accent: string}
     */
    private function defaultColors(): array
    {
        return [
            'primary' => '#2563eb',
            'secondary' => '#0f766e',
            'accent' => '#f59e0b',
        ];
    }
}
