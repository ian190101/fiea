<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Invoice;
use App\Services\Security\ScopeService;

class SystemNotificationService
{
    /**
     * @param array<string, mixed> $data
     */
    public function notifyPermission(
        string $permission,
        string $type,
        string $severity,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?User $actor = null,
        array $data = [],
    ): int {
        $created = 0;
        $invoicePhaseId = isset($data['invoice_id'])
            ? Invoice::query()->whereKey($data['invoice_id'])->value('trip_phase_id') : null;

        User::query()
            ->where('is_active', true)
            ->with('roles.permissions:id,code')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($permission, $type, $severity, $title, $body, $actionUrl, $actor, $data, $invoicePhaseId, &$created) {
                foreach ($users as $user) {
                    if (! $user->hasPermission($permission)) {
                        continue;
                    }

                    if (isset($data['invoice_id'])) {
                        if (!$invoicePhaseId || !app(ScopeService::class)->permits($user, (int) $invoicePhaseId)) {
                            continue;
                        }
                    } elseif (isset($data['trip_phase_id']) && !app(ScopeService::class)->permits($user, (int) $data['trip_phase_id'])) {
                        continue;
                    } elseif (!isset($data['invoice_id']) && !isset($data['trip_phase_id']) && !$user->scope_all) {
                        continue;
                    }

                    $host = $actionUrl === null ? null : parse_url($actionUrl, PHP_URL_HOST);
                    abort_unless($actionUrl === null || ($host === null && str_starts_with($actionUrl, '/') && !str_starts_with($actionUrl, '//'))
                        || ($host !== null && $host === parse_url((string) config('app.url'), PHP_URL_HOST)), 422);

                    SystemNotification::query()->create([
                        'user_id' => $user->id,
                        'created_by_id' => $actor?->id,
                        'type' => $type,
                        'severity' => $severity,
                        'title' => $title,
                        'body' => $body,
                        'action_url' => $actionUrl,
                        'data' => $data,
                    ]);

                    $created++;
                }
            });

        return $created;
    }
}
