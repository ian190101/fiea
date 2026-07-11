import IconButton, { SvgIcon } from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const severityMeta = {
    critical: 'bg-red-500 text-white',
    warning: 'bg-amber-500 text-white',
    info: 'bg-brand-primary text-white',
};

const statusOptions = [
    { value: '', label: 'Todas' },
    { value: 'unread', label: 'No leidas' },
    { value: 'read', label: 'Leidas' },
];

export default function NotificationsIndex({ filters = {}, notifications = { data: [] } }) {
    const { flash } = usePage().props;
    const [status, setStatus] = useState(filters.status ?? '');

    const applyFilter = (value) => {
        setStatus(value);
        router.get(route('notifications.index'), value ? { status: value } : {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const markAllRead = () => {
        router.patch(route('notifications.read-all'), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Notificaciones</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Bandeja interna</h1>
                </div>
            }
        >
            <Head title="Notificaciones" />

            <div className="max-w-6xl space-y-6">
                <FlashMessage flash={flash} />

                <section className="glass-panel rounded-[2rem] p-5">
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 className="text-xl font-black">Eventos del sistema</h2>
                            <p className="mt-1 text-sm text-app-muted">Invoices, correos, backups y operaciones importantes.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <select value={status} onChange={(event) => applyFilter(event.target.value)} className="ios-field text-sm font-bold">
                                {statusOptions.map((option) => (
                                    <option key={option.value} value={option.value}>{option.label}</option>
                                ))}
                            </select>
                            <button type="button" className="glass-button gap-2" onClick={markAllRead}>
                                <SvgIcon name="check" />
                                <span>Marcar todas</span>
                            </button>
                        </div>
                    </div>
                </section>

                <section className="glass-panel overflow-hidden rounded-[2rem]">
                    <div className="divide-y divide-white/25 dark:divide-white/10">
                        {notifications.data.map((notification) => (
                            <NotificationRow key={notification.id} notification={notification} />
                        ))}

                        {notifications.data.length === 0 && (
                            <div className="px-5 py-14 text-center">
                                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-secondary text-white">
                                    <SvgIcon name="check" />
                                </div>
                                <p className="mt-4 text-base font-black">No hay notificaciones para este filtro</p>
                                <p className="mt-1 text-sm text-app-muted">Los eventos nuevos apareceran automaticamente en esta bandeja.</p>
                            </div>
                        )}
                    </div>

                    {(notifications.prev_page_url || notifications.next_page_url) && (
                        <div className="flex items-center justify-between border-t border-white/30 px-5 py-4 dark:border-white/10">
                            {notifications.prev_page_url ? (
                                <Link href={notifications.prev_page_url} className="glass-button">Anterior</Link>
                            ) : <span />}
                            {notifications.next_page_url && (
                                <Link href={notifications.next_page_url} className="glass-button">Siguiente</Link>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function NotificationRow({ notification }) {
    const unread = !notification.read_at;

    const markRead = () => {
        router.patch(route('notifications.read', notification.id), {}, {
            preserveScroll: true,
        });
    };

    return (
        <article className={`grid gap-4 px-5 py-4 transition hover:bg-white/20 dark:hover:bg-white/5 md:grid-cols-[auto_1fr_auto] md:items-center ${unread ? 'bg-white/20 dark:bg-white/5' : ''}`}>
            <div className={`flex h-11 w-11 items-center justify-center rounded-2xl ${severityMeta[notification.severity] ?? severityMeta.info}`}>
                <SvgIcon name={notification.severity === 'critical' ? 'warning' : 'info'} />
            </div>

            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    {unread && <span className="rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-black uppercase text-white">Nueva</span>}
                    <span className="rounded-full bg-white/40 px-3 py-1 text-xs font-black text-app-text dark:bg-white/10">
                        {notification.type.replaceAll('_', ' ')}
                    </span>
                    <span className="text-xs font-bold text-app-muted">{formatDateTime(notification.created_at)}</span>
                </div>
                <h3 className="mt-2 text-base font-black text-app-text">{notification.title}</h3>
                {notification.body && <p className="mt-1 text-sm text-app-muted">{notification.body}</p>}
                {notification.created_by && (
                    <p className="mt-1 text-xs font-bold text-app-muted">Por {notification.created_by.name}</p>
                )}
            </div>

            <div className="flex justify-end gap-2">
                {notification.action_url && (
                    <IconButton as="a" href={notification.action_url} icon="arrowRight" label="Abrir destino" />
                )}
                {unread && (
                    <IconButton icon="check" label="Marcar como leida" type="button" onClick={markRead} />
                )}
            </div>
        </article>
    );
}

function FlashMessage({ flash }) {
    if (!flash.success) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
            {flash.success}
        </div>
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
