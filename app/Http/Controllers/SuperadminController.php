<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Access\StoreRole;
use App\Actions\Access\StoreUser;
use App\Actions\Access\UpdateRole;
use App\Actions\Access\UpdateUser;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserScopeRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\UpdateUserScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SuperadminController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->scope_all && $request->user()->hasPermission('superadmin.manage'), 403);

        return Inertia::render('Superadmin/Index', [
            'users' => Inertia::defer(fn () => User::query()
                ->with(['roles:id,code,name', 'projectGrants:id,name', 'chapterGrants:id,name'])
                ->orderBy('name')->orderBy('id')
                ->cursorPaginate(25, ['id', 'name', 'username', 'email', 'must_change_password',
                    'theme_preference', 'is_active', 'scope_all', 'created_at']), 'superadmin'),
            'canManageScope' => (bool) ($request->user()->scope_all && $request->user()->hasPermission('scope.manage')),
            'roles' => Inertia::defer(fn () => Role::query()
                ->with('permissions:id,code,module,name')->withCount('users')
                ->orderBy('name')->get(), 'superadmin'),
            'permissions' => Inertia::defer(fn () => Permission::query()
                ->orderBy('module')->orderBy('name')
                ->get(['id', 'code', 'module', 'name'])->groupBy('module'), 'superadmin'),
        ]);
    }

    public function storeUser(StoreUserRequest $request, StoreUser $action): RedirectResponse
    {
        $action->execute($request->user(), $request->validated());

        return back()->with('success', 'Usuario creado correctamente.');
    }

    public function updateUser(UpdateUserRequest $request, User $user,
        UpdateUser $action): RedirectResponse
    {
        $action->execute($request->user(), $user, $request->validated());

        return back()->with('success', 'Usuario actualizado correctamente.');
    }

    public function updateScope(UpdateUserScopeRequest $request, User $user,
        UpdateUserScope $action): RedirectResponse
    {
        $data = $request->validated();
        $action->execute($request->user(), $user, $data['project_ids'], $data['chapter_ids'],
            (bool) $data['scope_all']);

        return back()->with('success', 'Ámbito del usuario actualizado correctamente.');
    }

    public function storeRole(StoreRoleRequest $request, StoreRole $action): RedirectResponse
    {
        $action->execute($request->user(), $request->validated());

        return back()->with('success', 'Rol creado correctamente.');
    }

    public function updateRole(UpdateRoleRequest $request, Role $role,
        UpdateRole $action): RedirectResponse
    {
        $action->execute($request->user(), $role, $request->validated());

        return back()->with('success', 'Rol actualizado correctamente.');
    }
}
