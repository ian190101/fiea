<?php
declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

trait ExplicitAccess
{
    /** @param list<string> $permissions */
    private function authorizedUser(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->forceFill(['scope_all' => true])->save();
        $role = Role::query()->create([
            'code' => 'fixture_'.Str::lower(Str::random(12)), 'name' => 'Acceso explícito de prueba',
        ]);
        foreach ($permissions as $code) {
            $module = explode('.', $code, 2)[0];
            $permission = Permission::query()->firstOrCreate(['code' => $code], [
                'module' => $module, 'name' => $code,
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
