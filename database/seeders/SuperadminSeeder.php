<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('superadmin.password');
        if (!is_string($password) || strlen($password) < 10) {
            throw new \RuntimeException('Configura FIEA_SUPERADMIN_PASSWORD con al menos 10 caracteres.');
        }

        DB::transaction(function () use ($password): void {
            $role = Role::query()->firstOrCreate(
                ['code' => 'superadmin'],
                ['name' => 'Superadministrador']
            );
            $role->permissions()->sync(Permission::query()->pluck('id'));

            $user = User::query()->firstOrNew(['username' => 'ian']);
            $user->forceFill([
                'name' => 'Ian',
                'email' => $user->email ?: null,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'must_change_password' => false,
                'is_active' => true,
                'scope_all' => true,
                'remember_token' => null,
            ])->save();
            $user->roles()->sync([$role->id]);

            // La rotación de credenciales invalida sesiones previas sin alterar otras cuentas.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Se conserva el historial del administrador anterior, pero se revoca su acceso.
            $legacy = User::query()->where('username', 'admin.local')->where('id', '!=', $user->id)->first();
            if ($legacy) {
                $legacy->forceFill(['is_active' => false, 'remember_token' => null])->save();
                DB::table('sessions')->where('user_id', $legacy->id)->delete();
            }
        });
    }
}
