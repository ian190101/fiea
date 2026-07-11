import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const statusStyles = {
    ok: 'border-emerald-400/30 bg-emerald-400/15 text-emerald-800 dark:text-emerald-100',
    warning: 'border-amber-400/30 bg-amber-400/15 text-amber-800 dark:text-amber-100',
    failed: 'border-red-400/30 bg-red-400/15 text-red-800 dark:text-red-100',
};

const statusLabels = {
    ok: 'Operativo',
    warning: 'Atencion',
    failed: 'Fallo',
};

export default function OperationsIndex({ health = { checks: [] } }) {
    const checks = health.checks ?? [];
    const counts = checks.reduce((carry, check) => ({
        ...carry,
        [check.status]: (carry[check.status] ?? 0) + 1,
    }), {});

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Operacion</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Estado del sistema</h1>
                </div>
            }
        >
            <Head title="Estado del sistema" />

            <div className="max-w-7xl space-y-6">
                <section className="glass-panel rounded-[2rem] p-5">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="text-sm font-bold uppercase tracking-wide text-app-muted">Resumen operativo</p>
                            <h2 className="mt-2 text-2xl font-black">Salud general: {statusLabels[health.overall] ?? health.overall}</h2>
                            <p className="mt-2 text-sm text-app-muted">Ultima revision: {formatDateTime(health.checked_at)}</p>
                        </div>
                        <div className="grid grid-cols-3 gap-3 sm:min-w-[360px]">
                            <Counter label="Operativo" value={counts.ok ?? 0} status="ok" />
                            <Counter label="Atencion" value={counts.warning ?? 0} status="warning" />
                            <Counter label="Fallo" value={counts.failed ?? 0} status="failed" />
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    {checks.map((check) => (
                        <HealthCard key={check.name} check={check} />
                    ))}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Counter({ label, value, status }) {
    return (
        <div className={`rounded-2xl border px-4 py-3 text-center ${statusStyles[status]}`}>
            <p className="text-2xl font-black">{value}</p>
            <p className="text-xs font-black uppercase">{label}</p>
        </div>
    );
}

function HealthCard({ check }) {
    const entries = Object.entries(check.meta ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== '');

    return (
        <article className="glass-panel rounded-[2rem] p-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-black">{check.name}</h2>
                    <p className="mt-2 text-sm leading-6 text-app-muted">{check.detail}</p>
                </div>
                <span className={`shrink-0 rounded-full border px-3 py-1 text-xs font-black uppercase ${statusStyles[check.status]}`}>
                    {statusLabels[check.status] ?? check.status}
                </span>
            </div>

            {entries.length > 0 && (
                <dl className="mt-5 grid gap-2">
                    {entries.map(([key, value]) => (
                        <div key={key} className="flex items-center justify-between gap-4 rounded-2xl bg-white/30 px-4 py-3 text-sm dark:bg-white/10">
                            <dt className="font-bold text-app-muted">{humanize(key)}</dt>
                            <dd className="max-w-[58%] truncate text-right font-black">{formatValue(value)}</dd>
                        </div>
                    ))}
                </dl>
            )}
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

function formatValue(value) {
    if (Array.isArray(value)) {
        return value.length > 0 ? value.join(', ') : 'Ninguno';
    }

    if (typeof value === 'boolean') {
        return value ? 'Si' : 'No';
    }

    return String(value);
}

function humanize(value) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
