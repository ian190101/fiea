<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

final class AdministrativeContinuity
{
    public function lock(): void
    {
        Permission::query()->where('code', 'superadmin.manage')->orderBy('id')->lockForUpdate()->get();
    }

    /** @param list<int> $roles */
    public function ensure(User $target, bool $active, bool $global, array $roles): void
    {
        if (! $target->is_active || ! $target->scope_all || ! $target->hasPermission('superadmin.manage')) {
            return;
        }
        $willManage = $active && $global && Role::query()->whereIn('id', $roles)
            ->whereHas('permissions', fn ($q) => $q->where('code', 'superadmin.manage'))->exists();
        if ($willManage) {
            return;
        }
        $others = User::query()->whereKeyNot($target->id)->where('is_active', true)->where('scope_all', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'superadmin.manage'))
            ->orderBy('id')->lockForUpdate()->get(['users.id']);
        abort_unless($others->isNotEmpty(), 422, 'Debe permanecer al menos un administrador activo con acceso global.');
    }

    /** @param list<int> $permissionIds */
    public function ensureRole(Role $changing, array $permissionIds): void
    {
        $permission = Permission::query()->where('code', 'superadmin.manage')->value('id');
        if ($permission === null) {
            return;
        }
        $admins = User::query()->where('is_active', true)->where('scope_all', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'superadmin.manage'))
            ->with('roles.permissions')->orderBy('id')->lockForUpdate()->get();
        if ($admins->isEmpty()) {
            return;
        }
        $retained = $admins->contains(fn (User $user): bool => $user->roles->contains(fn (Role $role): bool => $role->id === $changing->id ? in_array((int) $permission, array_map('intval', $permissionIds), true)
                : $role->permissions->contains('code', 'superadmin.manage')));
        abort_unless($retained, 422, 'El cambio de permisos dejaría al sistema sin un administrador global operativo.');
    }
}
