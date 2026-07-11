import { SvgIcon } from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Deferred, Head, Link } from '@inertiajs/react';

const invoiceLabels = {
    draft: 'Borrador',
    approved: 'Aprobado',
    sent: 'Enviado',
    paid: 'Pagado',
    void: 'Anulado',
};

const accountingLabels = {
    pending: 'Pendiente',
    reconciled: 'Reconciliado',
    flagged: 'Observado',
};

const toneClasses = {
    primary: 'bg-brand-primary text-white',
    secondary: 'bg-brand-secondary text-white',
    accent: 'bg-brand-accent text-white',
    danger: 'bg-red-500 text-white',
};

const dashboardDeferredProps = [
    'metrics',
    'financials',
    'invoiceStatus',
    'accountingStatus',
    'upcomingTrips',
    'attentionItems',
    'recentActivity',
];

export default function Dashboard({
    metrics = {},
    financials = {},
    invoiceStatus = [],
    accountingStatus = [],
    upcomingTrips = [],
    attentionItems = [],
    recentActivity = [],
    systemRules = {},
}) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Operacion FIEA
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Panel de control financiero
                    </h1>
                </div>
            }
        >
            <Head title="Panel" />

            <Deferred data={dashboardDeferredProps} fallback={<DashboardSkeleton systemRules={systemRules} />}>
                <div className="max-w-7xl space-y-6 pb-12">
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <MetricCard title="Invoices abiertos" value={metrics.open_invoices ?? 0} icon="file" tone="primary" />
                    <MetricCard title="Creditos por equipo" value={formatMoney(metrics.team_credits)} icon="download" tone="secondary" />
                    <MetricCard title="Gastos sin recibo" value={metrics.expenses_without_receipts ?? 0} icon="search" tone="accent" />
                    <MetricCard title="Comprobantes" value={metrics.receipts ?? 0} icon="check" tone="primary" />
                </section>

                <section className="grid gap-6 xl:grid-cols-[1.35fr_0.9fr]">
                    <div className="glass-panel rounded-[2rem] p-6">
                        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                            <div>
                                <h2 className="text-xl font-black text-app-text">Flujo financiero</h2>
                                <p className="mt-2 max-w-2xl text-sm leading-6 text-app-muted">
                                    Seguimiento de presupuesto, ejecucion real, invoices y conciliacion.
                                </p>
                            </div>
                            <Link href={route('trip-phases.index')} className="glass-button gap-2">
                                <SvgIcon name="file" />
                                <span>Nuevo viaje</span>
                            </Link>
                        </div>

                        <div className="mt-6 grid gap-4 md:grid-cols-3">
                            <AmountTile label="Estimado" value={financials.estimated_total} />
                            <AmountTile label="Real" value={financials.real_total} />
                            <AmountTile label="Variacion" value={financials.variance} tone={Number(financials.variance ?? 0) > 0 ? 'danger' : 'secondary'} />
                        </div>

                        <div className="mt-6 grid gap-4 lg:grid-cols-2">
                            <StatusChart title="Invoices por estado" rows={normalizeStatus(invoiceStatus, invoiceLabels)} />
                            <StatusChart title="Contabilidad" rows={normalizeStatus(accountingStatus, accountingLabels)} />
                        </div>
                    </div>

                    <aside className="glass-panel rounded-[2rem] p-6">
                        <h2 className="text-lg font-black text-app-text">Reglas activas</h2>
                        <div className="mt-5 space-y-3">
                            <Rule
                                label="Bloqueo de Final Invoice"
                                value={systemRules.lockFinalInvoiceByDefault ? 'Activo por defecto' : 'Editable por defecto'}
                            />
                            <Rule
                                label="Contabilidad"
                                value={systemRules.accountingCanEditSummary ? 'Puede corregir resumen' : 'Solo visualiza y reconcilia'}
                            />
                            <Rule label="PDFs oficiales" value="Ingles para Estados Unidos" />
                            <Rule label="Archivos" value="Preparado para Cloudflare R2" />
                        </div>
                    </aside>
                </section>

                <section className="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                    <div className="glass-panel rounded-[2rem] p-6">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <h2 className="text-xl font-black">Atencion requerida</h2>
                                <p className="text-sm text-app-muted">Accesos directos a trabajo pendiente</p>
                            </div>
                            <span className="rounded-2xl bg-white/35 px-3 py-1 text-sm font-black dark:bg-white/10">
                                {attentionItems.reduce((total, item) => total + Number(item.value ?? 0), 0)}
                            </span>
                        </div>
                        <div className="mt-5 grid gap-3">
                            {attentionItems.map((item) => (
                                <Link key={item.label} href={route(item.route)} className="glass-tile flex items-center justify-between gap-4 p-4 transition hover:-translate-y-0.5">
                                    <div>
                                        <p className="font-black">{item.label}</p>
                                        <p className="mt-1 text-sm text-app-muted">Abrir modulo relacionado</p>
                                    </div>
                                    <span className={`flex h-10 min-w-10 items-center justify-center rounded-2xl px-3 text-sm font-black ${toneClasses[item.tone] ?? toneClasses.primary}`}>
                                        {item.value}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>

                    <div className="glass-panel rounded-[2rem] p-6">
                        <h2 className="text-xl font-black">Proximos viajes</h2>
                        <div className="mt-5 space-y-3">
                            {upcomingTrips.map((trip) => (
                                <div key={trip.id} className="glass-tile grid gap-3 p-4 md:grid-cols-[1fr_auto] md:items-center">
                                    <div>
                                        <p className="font-black">{trip.project_code} - {trip.phase}</p>
                                        <p className="mt-1 text-sm text-app-muted">{trip.project_name}</p>
                                        <p className="mt-1 text-xs font-bold text-app-muted">{trip.team_name ?? 'Sin equipo'}</p>
                                    </div>
                                    <div className="text-left md:text-right">
                                        <p className="text-sm font-black">{formatDate(trip.starts_on)}</p>
                                        <p className="text-xs text-app-muted">{formatDate(trip.ends_on)}</p>
                                    </div>
                                </div>
                            ))}
                            {upcomingTrips.length === 0 && (
                                <EmptyState text="No hay viajes futuros registrados." />
                            )}
                        </div>
                    </div>
                </section>

                <section className="glass-panel rounded-[2rem] p-6">
                    <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 className="text-xl font-black">Actividad reciente</h2>
                            <p className="text-sm text-app-muted">Ultimos movimientos registrados en auditoria</p>
                        </div>
                        <Link href={route('audit-logs.index')} className="glass-button gap-2">
                            <SvgIcon name="search" />
                            <span>Ver auditoria</span>
                        </Link>
                    </div>
                    <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {recentActivity.map((activity) => (
                            <div key={activity.id} className="glass-tile p-4">
                                <p className="text-xs font-black uppercase text-brand-primary">{activity.module}</p>
                                <p className="mt-2 font-black">{formatAction(activity.action)}</p>
                                <p className="mt-1 text-sm text-app-muted">{activity.user}</p>
                                <p className="mt-3 text-xs font-bold text-app-muted">{formatDateTime(activity.created_at)}</p>
                            </div>
                        ))}
                        {recentActivity.length === 0 && (
                            <EmptyState text="Todavia no hay actividad auditada." />
                        )}
                    </div>
                </section>
                </div>
            </Deferred>
        </AuthenticatedLayout>
    );
}

function DashboardSkeleton({ systemRules = {} }) {
    return (
        <div className="max-w-7xl space-y-6 pb-12">
            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {['Invoices abiertos', 'Creditos por equipo', 'Gastos sin recibo', 'Comprobantes'].map((title) => (
                    <div key={title} className="glass-panel rounded-[2rem] p-5">
                        <div className="mb-5 h-11 w-11 animate-pulse rounded-2xl bg-white/45 dark:bg-white/10" />
                        <p className="text-sm font-semibold text-app-muted">{title}</p>
                        <div className="mt-3 h-8 w-24 animate-pulse rounded-2xl bg-white/45 dark:bg-white/10" />
                    </div>
                ))}
            </section>

            <section className="grid gap-6 xl:grid-cols-[1.35fr_0.9fr]">
                <div className="glass-panel rounded-[2rem] p-6">
                    <div className="h-7 w-56 animate-pulse rounded-2xl bg-white/45 dark:bg-white/10" />
                    <div className="mt-3 h-4 w-80 max-w-full animate-pulse rounded-2xl bg-white/35 dark:bg-white/10" />
                    <div className="mt-6 grid gap-4 md:grid-cols-3">
                        {[1, 2, 3].map((item) => (
                            <div key={item} className="glass-tile p-4">
                                <div className="h-4 w-20 animate-pulse rounded-2xl bg-white/40 dark:bg-white/10" />
                                <div className="mt-3 h-7 w-28 animate-pulse rounded-2xl bg-white/45 dark:bg-white/10" />
                            </div>
                        ))}
                    </div>
                    <div className="mt-6 grid gap-4 lg:grid-cols-2">
                        {[1, 2].map((item) => (
                            <div key={item} className="glass-tile p-4">
                                <div className="h-5 w-40 animate-pulse rounded-2xl bg-white/45 dark:bg-white/10" />
                                <div className="mt-4 space-y-3">
                                    {[1, 2, 3].map((bar) => (
                                        <div key={bar} className="h-3 animate-pulse rounded-full bg-white/35 dark:bg-white/10" />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <aside className="glass-panel rounded-[2rem] p-6">
                    <h2 className="text-lg font-black text-app-text">Reglas activas</h2>
                    <div className="mt-5 space-y-3">
                        <Rule
                            label="Bloqueo de Final Invoice"
                            value={systemRules.lockFinalInvoiceByDefault ? 'Activo por defecto' : 'Editable por defecto'}
                        />
                        <Rule
                            label="Contabilidad"
                            value={systemRules.accountingCanEditSummary ? 'Puede corregir resumen' : 'Solo visualiza y reconcilia'}
                        />
                        <Rule label="PDFs oficiales" value="Ingles para Estados Unidos" />
                        <Rule label="Archivos" value="Preparado para Cloudflare R2" />
                    </div>
                </aside>
            </section>
        </div>
    );
}

function MetricCard({ title, value, icon, tone }) {
    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <div className={`mb-5 flex h-11 w-11 items-center justify-center rounded-2xl ${toneClasses[tone] ?? toneClasses.primary}`}>
                <SvgIcon name={icon} className="h-5 w-5" />
            </div>
            <p className="text-sm font-semibold text-app-muted">{title}</p>
            <p className="mt-2 text-3xl font-black text-app-text">{value}</p>
        </div>
    );
}

function AmountTile({ label, value, tone = 'primary' }) {
    return (
        <div className="glass-tile p-4">
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className={`mt-2 text-2xl font-black ${tone === 'danger' ? 'text-red-600 dark:text-red-300' : 'text-app-text'}`}>
                {formatMoney(value)}
            </p>
        </div>
    );
}

function StatusChart({ title, rows }) {
    const total = Math.max(rows.reduce((sum, row) => sum + row.count, 0), 1);

    return (
        <div className="glass-tile p-4">
            <h3 className="font-black">{title}</h3>
            <div className="mt-4 space-y-3">
                {rows.map((row) => (
                    <div key={row.status}>
                        <div className="mb-1 flex justify-between gap-3 text-sm font-bold">
                            <span>{row.label}</span>
                            <span className="text-app-muted">{row.count}</span>
                        </div>
                        <div className="h-3 overflow-hidden rounded-full bg-white/35 dark:bg-white/10">
                            <div className="h-full rounded-full bg-brand-primary" style={{ width: `${Math.max((row.count / total) * 100, 5)}%` }} />
                        </div>
                    </div>
                ))}
                {rows.length === 0 && <p className="text-sm font-semibold text-app-muted">Sin datos todavia.</p>}
            </div>
        </div>
    );
}

function Rule({ label, value }) {
    return (
        <div className="glass-tile p-4">
            <p className="text-xs font-semibold uppercase text-app-muted">{label}</p>
            <p className="mt-1 text-sm font-bold text-app-text">{value}</p>
        </div>
    );
}

function EmptyState({ text }) {
    return (
        <div className="rounded-2xl border border-dashed border-white/40 px-4 py-8 text-center text-sm font-bold text-app-muted dark:border-white/10">
            {text}
        </div>
    );
}

function normalizeStatus(rows, labels) {
    return rows.map((row) => ({
        status: row.status,
        label: labels[row.status] ?? row.status,
        count: Number(row.count ?? 0),
    }));
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`));
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

function formatAction(value) {
    return String(value ?? '')
        .replaceAll('_', ' ')
        .replace(/^\w/, (letter) => letter.toUpperCase());
}
