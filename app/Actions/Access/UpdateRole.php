<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AdministrativeContinuity;
use Illuminate\Support\Facades\DB;

final class UpdateRole
{
    public function execute(User $actor, Role $role, array $data): Role
    {
        return DB::transaction(function () use ($actor, $role, $data): Role {
            $continuity = app(AdministrativeContinuity::class);
            $continuity->lock();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->scope_all && $actor->hasPermission('superadmin.manage'), 403);
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $before = $role->only(['code', 'name', 'description']);
            $before['permissions'] = $role->permissions()->pluck('code')->all();
            $permissionIds = $role->code === 'superadmin' ? Permission::query()->pluck('id')->all() : ($data['permission_ids'] ?? []);
            $continuity->ensureRole($role, $permissionIds);
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
            $role->permissions()->sync($permissionIds);
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'role_updated', 'module' => 'superadmin',
                'auditable_type' => Role::class, 'auditable_id' => $role->id,
                'metadata' => ['before' => $before, 'after' => $role->only(['code', 'name', 'description']),
                    'permissions' => $role->permissions()->pluck('code')->all()],
            ]);

            return $role;
        }, 3);
    }
}
