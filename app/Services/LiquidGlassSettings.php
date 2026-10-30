<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class LiquidGlassSettings
{
    public const CACHE_KEY = 'fiea:liquid-glass:settings';

    /** @return array<string, array<string, bool|float|int>> */
    public function defaults(): array
    {
        $base = config('liquid-glass.defaults');

        return array_map(fn (array $preset): array => array_replace($base, $preset), config('liquid-glass.presets'));
    }

    /** @return array<string, array<string, bool|float|int>> */
    public function current(): array
    {
        $load = fn (): array => Schema::hasColumn('system_settings', 'liquid_glass')
            ? (SystemSetting::query()->value('liquid_glass') ?? []) : [];
        try {
            $stored = app()->environment('testing') ? $load() : Cache::remember(self::CACHE_KEY, 300, $load);
        } catch (\Throwable $e) {
            report($e);
            $stored = $load();
        }
        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?? [];
        }

        return $this->normalize(is_array($stored) ? $stored : []);
    }

    public function normalize(array $profiles): array
    {
        $result = $this->defaults();
        foreach ($result as $name => &$profile) {
            foreach ($profile as $key => &$value) {
                $raw = $profiles[$name][$key] ?? $value;
                if (in_array($key, ['button', 'floating'], true)) {
                    $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
                } elseif ($key === 'bevelMode') {
                    $value = in_array($raw, [0, 1, '0', '1'], true) ? (int) $raw : $value;
                } elseif (is_numeric($raw) && is_finite((float) $raw)) {
                    [$min,$max] = config('liquid-glass.ranges.'.$key, [-INF, INF]);
                    if ((float) $raw >= $min && (float) $raw <= $max) {
                        $value = (float) $raw;
                    }
                }
            }
        }
        unset($profile,$value);

        return $result;
    }
}
