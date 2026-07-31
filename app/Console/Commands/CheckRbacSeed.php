<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;

class CheckRbacSeed extends Command
{
    protected $signature = 'fiea:rbac-seed-check';

    protected $description = 'Verifica que permisos y rol superadmin esten correctamente sembrados.';

    public function handle(): int
    {
        $permissionCount = Permission::query()->count();
        $superadmin = Role::query()->withCount('permissions')->where('code', 'superadmin')->first();

        if ($permissionCount === 0) {
            $this->error('RBAC invalido: no existen permisos sembrados.');

            return self::FAILURE;
        }

        if (! $superadmin) {
            $this->error('RBAC invalido: no existe el rol superadmin.');

            return self::FAILURE;
        }

        if ($superadmin->permissions_count !== $permissionCount) {
            $this->error("RBAC invalido: superadmin tiene {$superadmin->permissions_count} de {$permissionCount} permisos.");

            return self::FAILURE;
        }

        $this->info("RBAC OK: {$permissionCount} permisos y superadmin completo.");

        return self::SUCCESS;
    }
}
