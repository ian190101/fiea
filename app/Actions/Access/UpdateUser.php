<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AdministrativeContinuity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateUser
{
    public function execute(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            $continuity = app(AdministrativeContinuity::class);
            $continuity->lock();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->scope_all && $actor->hasPermission('superadmin.manage'), 403);
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $continuity->ensure($locked, (bool) $data['is_active'], (bool) $locked->scope_all, $data['role_ids'] ?? []);
            $before = $locked->load('roles:id,code')->only(['name', 'username', 'email',
                'must_change_password', 'theme_preference', 'is_active']);
            $before['roles'] = $locked->roles->pluck('code')->all();
            $superadmin = Role::query()->where('code', 'superadmin')->lockForUpdate()->first();
            $was = $superadmin && $locked->roles()->whereKey($superadmin->id)->exists();
            $will = $superadmin && in_array($superadmin->id, $data['role_ids'] ?? [], false);
            if ($locked->is_active && $was && (! (bool) $data['is_active'] || ! $will)) {
                $other = User::query()->where('is_active', true)->whereKeyNot($locked->id)
                    ->whereHas('roles', fn ($q) => $q->where('roles.id', $superadmin->id))
                    ->lockForUpdate()->exists();
                abort_unless($other, 422, 'No puedes desactivar al último superadministrador activo.');
            }
            $changedPassword = filled($data['password'] ?? null);
            $payload = collect($data)->only(['name', 'username', 'email', 'must_change_password',
                'theme_preference', 'is_active'])->all();
            if ($changedPassword) {
                $payload['password'] = $data['password'];
                $payload['must_change_password'] = true;
            }
            $locked->fill($payload)->save();
            $locked->roles()->sync($data['role_ids'] ?? []);
            if (! $locked->is_active || $changedPassword) {
                $locked->forceFill(['remember_token' => Str::random(60)])->save();
                if (config('session.driver') === 'database') {
                    DB::table('sessions')->where('user_id', $locked->id)->delete();
                }
            }
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'user_updated', 'module' => 'superadmin',
                'auditable_type' => User::class, 'auditable_id' => $locked->id,
                'metadata' => ['before' => $before,
                    'after' => $locked->only(['name', 'username', 'email', 'must_change_password',
                        'theme_preference', 'is_active']),
                    'roles' => $locked->roles()->pluck('code')->all(),
                    'password_changed' => $changedPassword],
            ]);

            return $locked;
        }, 3);
    }
}
