<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SuperadminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Superadmin/Index', [
            'users' => Inertia::defer(fn () => User::query()
                ->with('roles:id,code,name')
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'email', 'must_change_password', 'theme_preference', 'is_active', 'created_at']), 'superadmin'),
            'roles' => Inertia::defer(fn () => Role::query()
                ->with('permissions:id,code,module,name')
                ->withCount('users')
                ->orderBy('name')
                ->get(), 'superadmin'),
            'permissions' => Inertia::defer(fn () => Permission::query()
                ->orderBy('module')
                ->orderBy('name')
                ->get(['id', 'code', 'module', 'name'])
                ->groupBy('module'), 'superadmin'),
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'must_change_password' => ['required', 'boolean'],
            'theme_preference' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
            'is_active' => ['required', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        DB::transaction(function () use ($request, $data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'must_change_password' => (bool) $data['must_change_password'],
                'theme_preference' => $data['theme_preference'],
                'is_active' => (bool) $data['is_active'],
            ]);
            $user->roles()->sync($data['role_ids'] ?? []);

            $this->audit($request, 'user_created', $user, [
                'roles' => $user->roles()->pluck('code')->all(),
            ]);
        });

        return back()->with('success', 'Usuario creado correctamente.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'must_change_password' => ['required', 'boolean'],
            'theme_preference' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
            'is_active' => ['required', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        if ($request->user()?->is($user) && !$data['is_active']) {
            return back()->withErrors(['user' => 'No puedes desactivar tu propio usuario.']);
        }

        $before = $user->load('roles:id,code')->only(['name', 'username', 'email', 'must_change_password', 'theme_preference', 'is_active']);
        $before['roles'] = $user->roles->pluck('code')->all();

        DB::transaction(function () use ($request, $user, $data, $before) {
            $payload = [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'must_change_password' => (bool) $data['must_change_password'],
                'theme_preference' => $data['theme_preference'],
                'is_active' => (bool) $data['is_active'],
            ];

            if (filled($data['password'] ?? null)) {
                $payload['password'] = $data['password'];
                $payload['must_change_password'] = true;
            }

            $user->fill($payload)->save();
            $user->roles()->sync($data['role_ids'] ?? []);

            $this->audit($request, 'user_updated', $user, [
                'before' => $before,
                'after' => [
                    ...$user->fresh()->only(['name', 'username', 'email', 'must_change_password', 'theme_preference', 'is_active']),
                    'roles' => $user->roles()->pluck('code')->all(),
                    'password_changed' => filled($data['password'] ?? null),
                ],
            ]);
        });

        return back()->with('success', 'Usuario actualizado correctamente.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80', 'alpha_dash', Rule::unique('roles', 'code')],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $code = $data['code'] ? Str::slug($data['code'], '_') : Str::slug($data['name'], '_');

        if (Role::query()->where('code', $code)->exists()) {
            return back()->withErrors(['code' => 'Ya existe un rol con ese codigo.']);
        }

        DB::transaction(function () use ($request, $data, $code) {
            $role = Role::query()->create([
                'name' => $data['name'],
                'code' => $code,
                'description' => $data['description'] ?? null,
            ]);
            $role->permissions()->sync($data['permission_ids'] ?? []);

            $this->audit($request, 'role_created', $role, [
                'permissions' => $role->permissions()->pluck('code')->all(),
            ]);
        });

        return back()->with('success', 'Rol creado correctamente.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $before = [
            ...$role->only(['code', 'name', 'description']),
            'permissions' => $role->permissions()->pluck('code')->all(),
        ];

        DB::transaction(function () use ($request, $role, $data, $before) {
            $role->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ])->save();

            if ($role->code === 'superadmin') {
                $role->permissions()->sync(Permission::query()->pluck('id'));
            } else {
                $role->permissions()->sync($data['permission_ids'] ?? []);
            }

            $this->audit($request, 'role_updated', $role, [
                'before' => $before,
                'after' => [
                    ...$role->fresh()->only(['code', 'name', 'description']),
                    'permissions' => $role->permissions()->pluck('code')->all(),
                ],
            ]);
        });

        return back()->with('success', 'Rol actualizado correctamente.');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(Request $request, string $action, object $auditable, array $metadata): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'module' => 'superadmin',
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'metadata' => $metadata,
        ]);
    }
}
