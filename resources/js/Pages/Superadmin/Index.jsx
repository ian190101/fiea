import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

const themeOptions = [
    { id: 'system', name: 'Sistema' },
    { id: 'light', name: 'Claro' },
    { id: 'dark', name: 'Oscuro' },
];

export default function SuperadminIndex({ users = [], roles = [], permissions = {} }) {
    const { flash } = usePage().props;
    const [editingUser, setEditingUser] = useState(null);
    const [editingRole, setEditingRole] = useState(null);

    const userForm = useForm(defaultUserForm());
    const roleForm = useForm(defaultRoleForm());
    const permissionModules = useMemo(() => Object.entries(permissions), [permissions]);
    const userSearchText = useCallback((user) => [
        user.name,
        user.username,
        user.email,
        ...(user.roles ?? []).map((role) => role.name),
    ].filter(Boolean).join(' '), []);
    const roleSearchText = useCallback((role) => [
        role.name,
        role.code,
        role.description,
        ...(role.permissions ?? []).map((permission) => permission.name),
    ].filter(Boolean).join(' '), []);
    const userTable = useLocalTable(users, userSearchText, 10);
    const roleTable = useLocalTable(roles, roleSearchText, 10);

    const submitUser = (event) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => resetUserForm(),
        };

        if (editingUser) {
            userForm.patch(route('superadmin.users.update', editingUser.id), options);
            return;
        }

        userForm.post(route('superadmin.users.store'), options);
    };

    const submitRole = (event) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => resetRoleForm(),
        };

        if (editingRole) {
            roleForm.patch(route('superadmin.roles.update', editingRole.id), options);
            return;
        }

        roleForm.post(route('superadmin.roles.store'), options);
    };

    const editUser = (user) => {
        setEditingUser(user);
        userForm.clearErrors();
        userForm.setData({
            name: user.name,
            username: user.username,
            email: user.email ?? '',
            password: '',
            must_change_password: Boolean(user.must_change_password),
            theme_preference: user.theme_preference ?? 'system',
            is_active: Boolean(user.is_active),
            role_ids: user.roles.map((role) => role.id),
        });
    };

    const editRole = (role) => {
        setEditingRole(role);
        roleForm.clearErrors();
        roleForm.setData({
            name: role.name,
            code: role.code,
            description: role.description ?? '',
            permission_ids: role.permissions.map((permission) => permission.id),
        });
    };

    const resetUserForm = () => {
        setEditingUser(null);
        userForm.clearErrors();
        userForm.setData(defaultUserForm());
    };

    const resetRoleForm = () => {
        setEditingRole(null);
        roleForm.clearErrors();
        roleForm.setData(defaultRoleForm());
    };

    const toggleUserRole = (roleId) => {
        userForm.setData('role_ids', toggleId(userForm.data.role_ids, roleId));
    };

    const toggleRolePermission = (permissionId) => {
        roleForm.setData('permission_ids', toggleId(roleForm.data.permission_ids, permissionId));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Superadmin</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Usuarios, roles y permisos</h1>
                </div>
            }
        >
            <Head title="Superadmin" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <div className="grid gap-4 md:grid-cols-3">
                    <MetricCard label="Usuarios" value={users.length} />
                    <MetricCard label="Roles" value={roles.length} />
                    <MetricCard label="Permisos" value={permissionModules.reduce((total, [, items]) => total + items.length, 0)} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[420px_1fr]">
                    <div className="space-y-6">
                        <form onSubmit={submitUser} className="glass-panel rounded-[2rem] p-5">
                            <h2 className="text-xl font-black">{editingUser ? 'Editar usuario' : 'Nuevo usuario'}</h2>
                            <div className="mt-5 space-y-4">
                                <TextField id="name" label="Nombre" value={userForm.data.name} error={userForm.errors.name} onChange={(value) => userForm.setData('name', value)} />
                                <TextField id="username" label="Username" value={userForm.data.username} error={userForm.errors.username} onChange={(value) => userForm.setData('username', value)} />
                                <TextField id="email" label="Correo" value={userForm.data.email} error={userForm.errors.email} onChange={(value) => userForm.setData('email', value)} />
                                <TextField id="password" label={editingUser ? 'Nueva password opcional' : 'Password'} type="password" value={userForm.data.password} error={userForm.errors.password} onChange={(value) => userForm.setData('password', value)} />

                                <SelectField
                                    id="theme_preference"
                                    label="Tema"
                                    value={userForm.data.theme_preference}
                                    options={themeOptions}
                                    error={userForm.errors.theme_preference}
                                    onChange={(value) => userForm.setData('theme_preference', value)}
                                />

                                <SwitchField label="Activo" checked={userForm.data.is_active} onChange={(value) => userForm.setData('is_active', value)} />
                                <SwitchField label="Debe cambiar password" checked={userForm.data.must_change_password} onChange={(value) => userForm.setData('must_change_password', value)} />

                                <CheckboxGroup
                                    label="Roles"
                                    items={roles}
                                    selectedIds={userForm.data.role_ids}
                                    onToggle={toggleUserRole}
                                />
                                <InputError message={userForm.errors.user} className="mt-2" />
                            </div>
                            <FormActions processing={userForm.processing} editing={Boolean(editingUser)} onCancel={resetUserForm} submitLabel={editingUser ? 'Guardar usuario' : 'Crear usuario'} />
                        </form>

                        <form onSubmit={submitRole} className="glass-panel rounded-[2rem] p-5">
                            <h2 className="text-xl font-black">{editingRole ? 'Editar rol' : 'Nuevo rol'}</h2>
                            <div className="mt-5 space-y-4">
                                <TextField id="role_name" label="Nombre" value={roleForm.data.name} error={roleForm.errors.name} onChange={(value) => roleForm.setData('name', value)} />
                                {!editingRole && (
                                    <TextField id="role_code" label="Codigo" value={roleForm.data.code} error={roleForm.errors.code} onChange={(value) => roleForm.setData('code', value)} />
                                )}
                                <TextField id="role_description" label="Descripcion" value={roleForm.data.description} error={roleForm.errors.description} onChange={(value) => roleForm.setData('description', value)} />

                                <div className="space-y-4">
                                    {permissionModules.map(([module, items]) => (
                                        <div key={module} className="rounded-2xl bg-white/30 p-3 dark:bg-white/10">
                                            <p className="text-xs font-black uppercase text-app-muted">{module}</p>
                                            <div className="mt-3 grid gap-2">
                                                {items.map((permission) => (
                                                    <label key={permission.id} className="flex items-center gap-3 text-sm font-bold">
                                                        <input
                                                            type="checkbox"
                                                            checked={roleForm.data.permission_ids.includes(permission.id)}
                                                            onChange={() => toggleRolePermission(permission.id)}
                                                            disabled={editingRole?.code === 'superadmin'}
                                                            className="rounded border-app-border text-brand-primary focus:ring-brand-primary"
                                                        />
                                                        <span>{permission.name}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <FormActions processing={roleForm.processing} editing={Boolean(editingRole)} onCancel={resetRoleForm} submitLabel={editingRole ? 'Guardar rol' : 'Crear rol'} />
                        </form>
                    </div>

                    <div className="space-y-6">
                        <div className="glass-panel overflow-hidden rounded-[2rem]">
                            <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                                <h2 className="text-xl font-black">Usuarios</h2>
                                <TableControls
                                    query={userTable.query}
                                    onQueryChange={userTable.updateQuery}
                                    page={userTable.page}
                                    totalPages={userTable.totalPages}
                                    pageSize={userTable.pageSize}
                                    onPageSizeChange={userTable.updatePageSize}
                                    onPageChange={userTable.setPage}
                                    total={users.length}
                                    filtered={userTable.filteredRows.length}
                                    placeholder="Buscar usuario"
                                />
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                    <thead>
                                        <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                            <th className="px-5 py-4">Usuario</th>
                                            <th className="px-5 py-4">Roles</th>
                                            <th className="px-5 py-4">Estado</th>
                                            <th className="px-5 py-4 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                        {userTable.paginatedRows.map((user) => (
                                            <tr key={user.id} className="text-sm">
                                                <td className="px-5 py-4">
                                                    <div className="font-black">{user.name}</div>
                                                    <div className="text-xs text-app-muted">@{user.username} · {user.email ?? '-'}</div>
                                                </td>
                                                <td className="px-5 py-4">
                                                    <PillList items={user.roles.map((role) => role.name)} />
                                                </td>
                                                <td className="px-5 py-4">
                                                    <StatusBadge active={user.is_active} />
                                                </td>
                                                <td className="px-5 py-4 text-right">
                                                    <IconButton icon="edit" label="Editar usuario" type="button" onClick={() => editUser(user)} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="glass-panel overflow-hidden rounded-[2rem]">
                            <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                                <h2 className="text-xl font-black">Roles</h2>
                                <TableControls
                                    query={roleTable.query}
                                    onQueryChange={roleTable.updateQuery}
                                    page={roleTable.page}
                                    totalPages={roleTable.totalPages}
                                    pageSize={roleTable.pageSize}
                                    onPageSizeChange={roleTable.updatePageSize}
                                    onPageChange={roleTable.setPage}
                                    total={roles.length}
                                    filtered={roleTable.filteredRows.length}
                                    placeholder="Buscar rol"
                                />
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                    <thead>
                                        <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                            <th className="px-5 py-4">Rol</th>
                                            <th className="px-5 py-4">Permisos</th>
                                            <th className="px-5 py-4">Usuarios</th>
                                            <th className="px-5 py-4 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                        {roleTable.paginatedRows.map((role) => (
                                            <tr key={role.id} className="text-sm">
                                                <td className="px-5 py-4">
                                                    <div className="font-black">{role.name}</div>
                                                    <div className="text-xs text-app-muted">{role.code}</div>
                                                </td>
                                                <td className="px-5 py-4">{role.permissions.length}</td>
                                                <td className="px-5 py-4">{role.users_count}</td>
                                                <td className="px-5 py-4 text-right">
                                                    <IconButton icon="edit" label="Editar rol" type="button" onClick={() => editRole(role)} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function FlashMessages({ flash }) {
    const error = flash.errors?.user?.[0] ?? Object.values(flash.errors ?? {})?.[0]?.[0];

    if (!flash.success && !error) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">{flash.success}</div>}
            {error && <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">{error}</div>}
        </div>
    );
}

function MetricCard({ label, value }) {
    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className="mt-2 text-2xl font-black text-app-text">{value}</p>
        </div>
    );
}

function TextField({ id, label, value, error, onChange, type = 'text' }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <TextInput id={id} type={type} value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="mt-1 block w-full" />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function SelectField({ id, label, value, options, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
            >
                {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
            </select>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function SwitchField({ label, checked, onChange }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-2xl bg-white/30 p-4 dark:bg-white/10">
            <p className="font-black">{label}</p>
            <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className={`flex h-8 w-14 shrink-0 items-center rounded-full p-1 transition ${checked ? 'bg-brand-primary' : 'bg-stone-400/60'}`}>
                <span className={`h-6 w-6 rounded-full bg-white shadow transition ${checked ? 'translate-x-6' : 'translate-x-0'}`} />
            </button>
        </div>
    );
}

function CheckboxGroup({ label, items, selectedIds, onToggle }) {
    return (
        <div className="rounded-2xl bg-white/30 p-4 dark:bg-white/10">
            <p className="text-sm font-black uppercase text-app-muted">{label}</p>
            <div className="mt-3 grid gap-2">
                {items.map((item) => (
                    <label key={item.id} className="flex items-center gap-3 text-sm font-bold">
                        <input type="checkbox" checked={selectedIds.includes(item.id)} onChange={() => onToggle(item.id)} className="rounded border-app-border text-brand-primary focus:ring-brand-primary" />
                        <span>{item.name}</span>
                    </label>
                ))}
            </div>
        </div>
    );
}

function FormActions({ processing, editing, onCancel, submitLabel }) {
    return (
        <div className="mt-6 flex flex-wrap gap-3">
            <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            {editing && <button type="button" className="glass-button" onClick={onCancel}>Cancelar</button>}
        </div>
    );
}

function PillList({ items }) {
    if (items.length === 0) {
        return <span className="text-app-muted">-</span>;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item) => <span key={item} className="rounded-full bg-white/40 px-2.5 py-1 text-xs font-black dark:bg-white/10">{item}</span>)}
        </div>
    );
}

function StatusBadge({ active }) {
    return (
        <span className={`rounded-full px-3 py-1 text-xs font-black uppercase ${active ? 'bg-emerald-400/20 text-emerald-800 dark:text-emerald-100' : 'bg-red-400/20 text-red-800 dark:text-red-100'}`}>
            {active ? 'Activo' : 'Inactivo'}
        </span>
    );
}

function defaultUserForm() {
    return {
        name: '',
        username: '',
        email: '',
        password: '',
        must_change_password: true,
        theme_preference: 'system',
        is_active: true,
        role_ids: [],
    };
}

function defaultRoleForm() {
    return {
        name: '',
        code: '',
        description: '',
        permission_ids: [],
    };
}

function toggleId(items, id) {
    return items.includes(id) ? items.filter((item) => item !== id) : [...items, id];
}
