import { SvgIcon } from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const severityMeta = {
    critical: {
        label: 'Critica',
        className: 'bg-red-500 text-white',
        ring: 'ring-red-400/30',
    },
    warning: {
        label: 'Advertencia',
        className: 'bg-amber-500 text-white',
        ring: 'ring-amber-400/30',
    },
    info: {
        label: 'Informativa',
        className: 'bg-brand-primary text-white',
        ring: 'ring-brand-primary/30',
    },
};

const typeLabels = {
    invoice: 'Invoices',
    accounting: 'Contabilidad',
    receipt: 'Recibos',
    email: 'Correos',
    trip: 'Viajes',
};

export default function AlertsIndex({ filters = {}, alerts = [], summary = {}, types = [], severities = [] }) {
    const [form, setForm] = useState({
        q: filters.q ?? '',
        type: filters.type ?? '',
        severity: filters.severity ?? '',
    });

    const activeFilters = useMemo(() => cleanParams(form), [form]);

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route('alerts.index'), activeFilters, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const clearFilters = () => {
        setForm({ q: '', type: '', severity: '' });
        router.get(route('alerts.index'), {}, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Alertas</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Centro de alertas</h1>
                </div>
            }
        >
            <Head title="Alertas" />

            <div className="max-w-7xl space-y-6 pb-12">
                <section className="grid gap-4 md:grid-cols-4">
                    <SummaryCard label="Total" value={summary.total} tone="bg-brand-secondary" />
                    <SummaryCard label="Criticas" value={summary.critical} tone="bg-red-500" />
                    <SummaryCard label="Advertencias" value={summary.warning} tone="bg-amber-500" />
                    <SummaryCard label="Informativas" value={summary.info} tone="bg-brand-primary" />
                </section>

                <form onSubmit={applyFilters} className="glass-panel rounded-[2rem] p-5">
                    <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr_1fr_auto] lg:items-end">
                        <div>
                            <label className="text-sm font-bold text-app-muted">Buscar</label>
                            <div className="relative mt-1">
                                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-app-muted">
                                    <SvgIcon name="search" />
                                </span>
                                <input
                                    type="search"
                                    value={form.q}
                                    onChange={(event) => setForm((current) => ({ ...current, q: event.target.value }))}
                                    placeholder="Codigo, proyecto o descripcion"
                                    className="ios-field block w-full pl-10"
                                />
                            </div>
                        </div>

                        <SelectField
                            label="Tipo"
                            value={form.type}
                            options={types}
                            placeholder="Todos"
                            onChange={(value) => setForm((current) => ({ ...current, type: value }))}
                        />

                        <SelectField
                            label="Severidad"
                            value={form.severity}
                            options={severities}
                            placeholder="Todas"
                            onChange={(value) => setForm((current) => ({ ...current, severity: value }))}
                        />

                        <div className="flex flex-wrap gap-2">
                            <button type="submit" className="glass-button gap-2">
                                <SvgIcon name="search" />
                                <span>Filtrar</span>
                            </button>
                            <button type="button" className="glass-button" onClick={clearFilters}>
                                Limpiar
                            </button>
                        </div>
                    </div>
                </form>

                <section className="glass-panel overflow-hidden rounded-[2rem]">
                    <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                        <h2 className="text-xl font-black">Alertas activas</h2>
                        <p className="text-sm text-app-muted">{alerts.length} resultados segun los filtros actuales</p>
                    </div>

                    <div className="divide-y divide-white/25 dark:divide-white/10">
                        {alerts.map((alert, index) => (
                            <AlertRow key={`${alert.type}-${alert.entity}-${index}`} alert={alert} />
                        ))}

                        {alerts.length === 0 && (
                            <div className="px-5 py-14 text-center">
                                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-secondary text-white">
                                    <SvgIcon name="check" />
                                </div>
                                <p className="mt-4 text-base font-black">Sin alertas para estos filtros</p>
                                <p className="mt-1 text-sm text-app-muted">El centro se actualiza automaticamente con la informacion de los modulos.</p>
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ label, value, tone }) {
    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <div className={`mb-5 h-2 w-16 rounded-full ${tone}`} />
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className="mt-2 text-3xl font-black text-app-text">{value}</p>
        </div>
    );
}

function SelectField({ label, value, options, placeholder, onChange }) {
    return (
        <div>
            <label className="text-sm font-bold text-app-muted">{label}</label>
            <select value={value} onChange={(event) => onChange(event.target.value)} className="ios-field mt-1 block w-full">
                <option value="">{placeholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function AlertRow({ alert }) {
    const meta = severityMeta[alert.severity] ?? severityMeta.info;

    return (
        <article className={`grid gap-4 px-5 py-4 ring-inset ${meta.ring} transition hover:bg-white/20 dark:hover:bg-white/5 md:grid-cols-[auto_1fr_auto] md:items-center`}>
            <div className={`flex h-11 w-11 items-center justify-center rounded-2xl ${meta.className}`}>
                <SvgIcon name={alert.severity === 'critical' ? 'warning' : 'info'} />
            </div>

            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="rounded-full bg-white/40 px-3 py-1 text-xs font-black text-app-text dark:bg-white/10">
                        {typeLabels[alert.type] ?? alert.type}
                    </span>
                    <span className={`rounded-full px-3 py-1 text-xs font-black ${meta.className}`}>
                        {meta.label}
                    </span>
                    {alert.date && <span className="text-xs font-bold text-app-muted">{formatDate(alert.date)}</span>}
                </div>
                <h3 className="mt-2 truncate text-base font-black text-app-text">{alert.title}</h3>
                <p className="mt-1 text-sm font-semibold text-app-muted">{alert.entity}</p>
                <p className="mt-1 text-sm text-app-muted">{alert.description}</p>
            </div>

            <Link href={alert.href} className="glass-button justify-center gap-2 md:min-w-32">
                <SvgIcon name="arrowRight" />
                <span>Revisar</span>
            </Link>
        </article>
    );
}

function cleanParams(form) {
    return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

function formatDate(value) {
    return new Intl.DateTimeFormat('es-BO', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    }).format(new Date(`${value}T00:00:00`));
}
