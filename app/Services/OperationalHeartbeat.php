<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class OperationalHeartbeat
{
    public function publish(string $component): void
    {
        if (! in_array($component, ['queue', 'scheduler'], true)) {
            throw new \InvalidArgumentException('Componente de latido inválido.');
        }
        try {
            Cache::put('fiea:'.$component.':heartbeat', now()->toIso8601String(), 300);
        } catch (\Throwable $e) {
            try {
                report($e);
            } catch (\Throwable) { /* La telemetría no interrumpe el trabajo principal. */
            }
        }
    }
}
