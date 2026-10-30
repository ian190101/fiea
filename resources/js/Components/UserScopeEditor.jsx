import { useForm, usePage } from '@inertiajs/react';
import FinancialActionSheet from '@/Components/FinancialActionSheet';
import RemoteMultiSelect from '@/Components/RemoteMultiSelect';

export default function UserScopeEditor({ user, onClose }) {
    const actorId = usePage().props.auth.user.id;
    const form = useForm(`Scope:${actorId}:${user.id}`, {
        scope_all: Boolean(user.scope_all),
        project_ids: (user.project_grants ?? []).map((item) => item.id),
        chapter_ids: (user.chapter_grants ?? []).map((item) => item.id),
    });
    return <FinancialActionSheet open title={`Ámbito de ${user.name}`} description="Revisa las concesiones antes de guardar. El acceso global incluye todos los proyectos y capítulos."
        confirmLabel="Guardar ámbito" busy={form.processing} onCancel={onClose}
        onConfirm={() => form.patch(route('superadmin.users.scope', user.id), { preserveScroll: true, onSuccess: onClose })}>
        <div className="mt-4 space-y-4">
            <label className="flex gap-3"><input type="checkbox" checked={form.data.scope_all}
                onChange={(event) => form.setData('scope_all', event.target.checked)} />Acceso global</label>
            <RemoteMultiSelect label="Proyectos" url={route('superadmin.scope-options', 'projects')} value={form.data.project_ids}
                selectedOptions={user.project_grants} disabled={form.data.scope_all || form.processing} onChange={(ids) => form.setData('project_ids', ids)} />
            <RemoteMultiSelect label="Capítulos" url={route('superadmin.scope-options', 'chapters')} value={form.data.chapter_ids}
                selectedOptions={user.chapter_grants} disabled={form.data.scope_all || form.processing} onChange={(ids) => form.setData('chapter_ids', ids)} />
            <p className="text-sm">Las asignaciones como técnico también otorgan acceso a sus viajes. Guardar requiere confirmación reciente de contraseña.</p>
            {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert">{message}</p>)}
        </div>
    </FinancialActionSheet>;
}
