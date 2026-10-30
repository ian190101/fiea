<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StoreUser
{
    public function execute(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->scope_all && $actor->hasPermission('superadmin.manage'), 403);
            $user = User::query()->create(collect($data)->only(['name', 'username', 'email', 'password',
                'must_change_password', 'theme_preference', 'is_active'])->all());
            $user->roles()->sync($data['role_ids'] ?? []);
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'user_created', 'module' => 'superadmin',
                'auditable_type' => User::class, 'auditable_id' => $user->id,
                'metadata' => ['roles' => $user->roles()->pluck('code')->all()],
            ]);

            return $user;
        }, 3);
    }
}
