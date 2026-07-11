import { SvgIcon } from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status, security = {}, activity = [] }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Perfil</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Cuenta y seguridad</h1>
                </div>
            }
        >
            <Head title="Perfil" />

            <div className="max-w-7xl space-y-6">
                <section className="grid gap-4 lg:grid-cols-3">
                    <SecurityMetric label="Ultimo acceso" value={formatDateTime(security.last_login_at)} />
                    <SecurityMetric label="IP registrada" value={security.last_login_ip ?? '-'} />
                    <SecurityMetric label="IP actual" value={security.current_ip ?? '-'} />
                </section>

                <section className="grid gap-6 xl:grid-cols-[1fr_420px]">
                    <div className="space-y-6">
                        <div className="glass-panel rounded-[2rem] p-5">
                            <UpdateProfileInformationForm
                                mustVerifyEmail={mustVerifyEmail}
                                status={status}
                            />
                        </div>

                        <div className="glass-panel rounded-[2rem] p-5">
                            <UpdatePasswordForm />
                        </div>

                        <div className="glass-panel rounded-[2rem] p-5">
                            <DeleteUserForm />
                        </div>
                    </div>

                    <aside className="space-y-6">
                        <div className="glass-panel rounded-[2rem] p-5">
                            <h2 className="text-xl font-black">Sesion actual</h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Esta informacion ayuda a detectar accesos o navegadores inesperados.
                            </p>
                            <div className="mt-4 rounded-2xl bg-white/30 p-4 text-sm font-semibold text-app-muted dark:bg-white/10">
                                {security.current_user_agent || 'Sin user agent'}
                            </div>
                        </div>

                        <div className="glass-panel overflow-hidden rounded-[2rem]">
                            <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                                <h2 className="text-xl font-black">Actividad reciente</h2>
                                <p className="text-sm text-app-muted">Ultimos eventos registrados con tu usuario.</p>
                            </div>
                            <div className="divide-y divide-white/25 dark:divide-white/10">
                                {activity.map((item) => (
                                    <ActivityRow key={item.id} item={item} />
                                ))}
                                {activity.length === 0 && (
                                    <div className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                        Todavia no hay actividad registrada.
                                    </div>
                                )}
                            </div>
                        </div>
                    </aside>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function SecurityMetric({ label, value }) {
    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className="mt-2 truncate text-xl font-black text-app-text">{value}</p>
        </div>
    );
}

function ActivityRow({ item }) {
    return (
        <article className="grid gap-3 px-5 py-4 text-sm">
            <div className="flex items-center gap-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-2xl bg-brand-primary text-white">
                    <SvgIcon name="info" />
                </div>
                <div className="min-w-0">
                    <p className="truncate font-black text-app-text">{humanize(item.action)}</p>
                    <p className="text-xs font-bold text-app-muted">{item.module} - {formatDateTime(item.created_at)}</p>
                </div>
            </div>
            <p className="truncate text-xs font-semibold text-app-muted">{item.ip_address ?? '-'} - {item.user_agent ?? '-'}</p>
        </article>
    );
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function humanize(value) {
    return String(value).replaceAll('_', ' ');
}
