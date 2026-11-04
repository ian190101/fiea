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
            'primary' => $settings?->primary_color ?? '#3960a3',
            'secondary' => $settings?->secondary_color ?? '#f78e3c',
            'accent' => $settings?->accent_color ?? '#007145',
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
            return asset('images/fiea-logo.png');
        }

        return route('branding.logo', ['v' => $file->checksum ?: $file->id]);
    }

    public function logoDataUri(): ?string
    {
        $file = $this->logoFile();

        if (! $file instanceof StorageFile) {
            $path = public_path('images/fiea-logo.png');

            return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
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

    public function documentStyleKey(): string
    {
        return hash('sha256', json_encode([FinancialPrintLayout::VERSION, $this->colors(),
            hash('sha256', $this->logoDataUri() ?? '')], JSON_THROW_ON_ERROR));
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
     *
     * @param  callable(): T  $callback
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
            'primary' => '#3960a3',
            'secondary' => '#f78e3c',
            'accent' => '#007145',
        ];
    }
}
