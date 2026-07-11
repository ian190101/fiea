<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;

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

        User::query()
            ->where('is_active', true)
            ->with('roles.permissions:id,code')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($permission, $type, $severity, $title, $body, $actionUrl, $actor, $data, &$created) {
                foreach ($users as $user) {
                    if (! $user->hasPermission($permission)) {
                        continue;
                    }

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
