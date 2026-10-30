<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StoreRole
{
    public function execute(User $actor, array $data): Role
    {
        return DB::transaction(function () use ($actor, $data): Role {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->scope_all && $actor->hasPermission('superadmin.manage'), 403);
            $code = Str::slug($data['code'] ?? $data['name'], '_');
            abort_unless(! Role::query()->where('code', $code)->exists(), 422,
                'Ya existe un rol con ese código.');
            $role = Role::query()->create(['name' => $data['name'], 'code' => $code,
                'description' => $data['description'] ?? null]);
            $role->permissions()->sync($data['permission_ids'] ?? []);
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'role_created', 'module' => 'superadmin',
                'auditable_type' => Role::class, 'auditable_id' => $role->id,
                'metadata' => ['permissions' => $role->permissions()->pluck('code')->all()],
            ]);

            return $role;
        }, 3);
    }
}
