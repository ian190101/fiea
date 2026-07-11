import InputLabel from '@/Components/InputLabel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function AuditLogIndex({ auditLogs = { data: [] }, filters = {}, filterOptions = { modules: [], actions: [], users: [] }, summary = {} }) {
    const [localFilters, setLocalFilters] = useState({
        module: filters.module ?? '',
        action: filters.action ?? '',
        user_id: filters.user_id ?? '',
    });

    const rows = auditLogs.data ?? [];
    const activeFilterCount = useMemo(
        () => Object.values(filters).filter((value) => value !== null && value !== '').length,
        [filters],
    );

    const applyFilters = (event) => {
        event.preventDefault();

        router.get(route('audit-logs.index'), compactFilters(localFilters), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const clearFilters = () => {
        setLocalFilters({ module: '', action: '', user_id: '' });
        router.get(route('audit-logs.index'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Auditoria</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Historial de cambios</h1>
                </div>
            }
        >
            <Head title="Auditoria" />

            <div className="max-w-7xl space-y-6">
                <div className="grid gap-4 md:grid-cols-4">
                    <MetricCard label="Eventos" value={summary.total} />
                    <MetricCard label="Hoy" value={summary.today} />
                    <MetricCard label="Contabilidad" value={summary.accounting} />
                    <MetricCard label="Sin usuario" value={summary.anonymous} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[360px_1fr]">
                    <form onSubmit={applyFilters} className="glass-panel h-fit rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">Filtros</h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Consulta eventos sin alterar el registro de auditoria.
                            </p>
                        </div>

                        <div className="mt-5 space-y-4">
                            <SelectField
                                id="module"
                                label="Modulo"
                                value={localFilters.module}
                                options={filterOptions.modules.map((module) => ({ id: module, name: module }))}
                                emptyLabel="Todos los modulos"
                                onChange={(value) => setLocalFilters((current) => ({ ...current, module: value }))}
                            />
                            <SelectField
                                id="action"
                                label="Accion"
                                value={localFilters.action}
                                options={filterOptions.actions.map((action) => ({ id: action, name: action }))}
                                emptyLabel="Todas las acciones"
                                onChange={(value) => setLocalFilters((current) => ({ ...current, action: value }))}
                            />
                            <SelectField
                                id="user_id"
                                label="Usuario"
                                value={localFilters.user_id}
                                options={filterOptions.users.map((user) => ({ id: user.id, name: `${user.name} (@${user.username})` }))}
                                emptyLabel="Todos los usuarios"
                                onChange={(value) => setLocalFilters((current) => ({ ...current, user_id: value }))}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <button type="submit" className="glass-button bg-brand-primary px-4 py-2 text-white">
                                Aplicar
                            </button>
                            {activeFilterCount > 0 && (
                                <button type="button" className="glass-button px-4 py-2" onClick={clearFilters}>
                                    Limpiar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <h2 className="text-xl font-black">Eventos registrados</h2>
                            <p className="text-sm text-app-muted">Paginacion por cursor, ordenada por eventos recientes.</p>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Fecha</th>
                                        <th className="px-5 py-4">Usuario</th>
                                        <th className="px-5 py-4">Modulo</th>
                                        <th className="px-5 py-4">Accion</th>
                                        <th className="px-5 py-4">Entidad</th>
                                        <th className="px-5 py-4">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {rows.map((log) => (
                                        <tr key={log.id} className="align-top text-sm">
                                            <td className="whitespace-nowrap px-5 py-4 font-bold">{formatDate(log.created_at)}</td>
                                            <td className="px-5 py-4">
                                                <div>{log.user?.name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{log.user?.username ? `@${log.user.username}` : log.ip_address ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <Badge>{log.module}</Badge>
                                            </td>
                                            <td className="px-5 py-4 font-bold">{log.action}</td>
                                            <td className="px-5 py-4">
                                                <div className="font-bold">{entityName(log.auditable_type)}</div>
                                                <div className="text-xs text-app-muted">ID {log.auditable_id ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <MetadataPreview metadata={log.metadata} />
                                            </td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan="6" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                No hay eventos para estos filtros.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-white/30 px-5 py-4 dark:border-white/10">
                            <span className="text-sm font-bold text-app-muted">{rows.length} eventos en esta pagina</span>
                            <div className="flex gap-2">
                                {auditLogs.prev_page_url && (
                                    <Link href={auditLogs.prev_page_url} className="glass-button px-3 py-2 text-xs">Anterior</Link>
                                )}
                                {auditLogs.next_page_url && (
                                    <Link href={auditLogs.next_page_url} className="glass-button px-3 py-2 text-xs">Siguiente</Link>
                                )}
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
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

function SelectField({ id, label, value, options, emptyLabel, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
            >
                <option value="">{emptyLabel}</option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>{option.name}</option>
                ))}
            </select>
        </div>
    );
}

function Badge({ children }) {
    return (
        <span className="rounded-full bg-white/40 px-3 py-1 text-xs font-black uppercase text-app-text dark:bg-white/10">
            {children}
        </span>
    );
}

function MetadataPreview({ metadata }) {
    if (!metadata) {
        return <span className="text-app-muted">-</span>;
    }

    return (
        <details className="max-w-xl">
            <summary className="cursor-pointer text-sm font-black text-brand-primary">Ver detalle</summary>
            <pre className="mt-3 max-h-72 overflow-auto rounded-2xl bg-stone-950/90 p-4 text-xs leading-5 text-stone-50">
                {JSON.stringify(metadata, null, 2)}
            </pre>
        </details>
    );
}

function compactFilters(filters) {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null && value !== ''),
    );
}

function entityName(value) {
    if (!value) {
        return '-';
    }

    return value.split('\\').pop();
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
