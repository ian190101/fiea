import { SvgIcon } from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const invoiceLabels = {
    draft: 'Borrador',
    approved: 'Aprobado',
    sent: 'Enviado',
    paid: 'Pagado',
    void: 'Anulado',
};

export default function ReportIndex({ filters = {}, projects = [], summary = {}, byProject = [], byFund = [], invoiceStatus = [], receiptCoverage = {} }) {
    const [form, setForm] = useState({
        start_date: filters.start_date ?? '',
        end_date: filters.end_date ?? '',
        project_id: filters.project_id ?? '',
    });

    const exportParams = useMemo(() => cleanParams(form), [form]);

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route('reports.index'), cleanParams(form), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const clearFilters = () => {
        setForm({ start_date: '', end_date: '', project_id: '' });
        router.get(route('reports.index'), {}, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Reportes</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Reportes financieros</h1>
                </div>
            }
        >
            <Head title="Reportes" />

            <div className="max-w-7xl space-y-6 pb-12">
                <form onSubmit={applyFilters} className="glass-panel rounded-[2rem] p-5">
                    <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1.3fr_auto] lg:items-end">
                        <DateField
                            label="Desde"
                            value={form.start_date}
                            onChange={(value) => setForm((current) => ({ ...current, start_date: value }))}
                        />
                        <DateField
                            label="Hasta"
                            value={form.end_date}
                            onChange={(value) => setForm((current) => ({ ...current, end_date: value }))}
                        />
                        <div>
                            <label className="text-sm font-bold text-app-muted">Proyecto</label>
                            <select
                                value={form.project_id}
                                onChange={(event) => setForm((current) => ({ ...current, project_id: event.target.value }))}
                                className="ios-field mt-1 block w-full"
                            >
                                <option value="">Todos los proyectos</option>
                                {projects.map((project) => (
                                    <option key={project.id} value={project.id}>
                                        {project.code} - {project.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button type="submit" className="glass-button gap-2">
                                <SvgIcon name="search" />
                                <span>Filtrar</span>
                            </button>
                            <button type="button" className="glass-button" onClick={clearFilters}>
                                Limpiar
                            </button>
                            <Link href={route('reports.export', exportParams)} className="glass-button gap-2">
                                <SvgIcon name="download" />
                                <span>CSV</span>
                            </Link>
                        </div>
                    </div>
                </form>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <MetricCard label="Estimado" value={formatMoney(summary.estimated_total)} tone="primary" />
                    <MetricCard label="Real" value={formatMoney(summary.actual_total)} tone="secondary" />
                    <MetricCard label="Variacion" value={formatMoney(summary.variance)} tone={Number(summary.variance ?? 0) > 0 ? 'danger' : 'secondary'} />
                    <MetricCard label="Balance" value={formatMoney(summary.balance_conciliation)} tone="accent" />
                </section>

                <section className="grid gap-6 xl:grid-cols-[1fr_0.85fr]">
                    <div className="glass-panel rounded-[2rem] p-5">
                        <h2 className="text-xl font-black">Presupuesto vs real por fondo</h2>
                        <div className="mt-5 space-y-5">
                            {byFund.map((row) => (
                                <FundComparison key={row.fund_type} row={row} />
                            ))}
                        </div>
                    </div>

                    <div className="glass-panel rounded-[2rem] p-5">
                        <h2 className="text-xl font-black">Cobertura de comprobantes</h2>
                        <p className="mt-1 text-sm text-app-muted">Gastos reales con y sin recibo asociado</p>
                        <div className="mt-6">
                            <CoverageChart coverage={receiptCoverage} />
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[1fr_0.85fr]">
                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <h2 className="text-xl font-black">Resultado por proyecto</h2>
                            <p className="text-sm text-app-muted">{byProject.length} proyectos con movimiento</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Proyecto</th>
                                        <th className="px-5 py-4 text-right">Estimado</th>
                                        <th className="px-5 py-4 text-right">Real</th>
                                        <th className="px-5 py-4 text-right">Variacion</th>
                                        <th className="px-5 py-4 text-right">Invoices</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {byProject.map((row) => (
                                        <tr key={row.project_id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-black">{row.project_code}</div>
                                                <div className="text-xs text-app-muted">{row.project_name}</div>
                                            </td>
                                            <td className="px-5 py-4 text-right">{formatMoney(row.estimated_total)}</td>
                                            <td className="px-5 py-4 text-right">{formatMoney(row.actual_total)}</td>
                                            <td className={`px-5 py-4 text-right font-black ${Number(row.variance) > 0 ? 'text-red-600 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-200'}`}>
                                                {formatMoney(row.variance)}
                                            </td>
                                            <td className="px-5 py-4 text-right">{formatMoney(row.invoice_total)}</td>
                                        </tr>
                                    ))}
                                    {byProject.length === 0 && (
                                        <tr>
                                            <td colSpan="5" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                No hay datos para los filtros seleccionados.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="glass-panel rounded-[2rem] p-5">
                        <h2 className="text-xl font-black">Invoices por estado</h2>
                        <div className="mt-5 space-y-4">
                            {invoiceStatus.map((row) => (
                                <InvoiceStatusRow key={row.status} row={row} />
                            ))}
                            {invoiceStatus.length === 0 && (
                                <p className="text-sm font-semibold text-app-muted">Sin invoices para estos filtros.</p>
                            )}
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function DateField({ label, value, onChange }) {
    return (
        <div>
            <label className="text-sm font-bold text-app-muted">{label}</label>
            <input
                type="date"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="ios-field mt-1 block w-full"
            />
        </div>
    );
}

function MetricCard({ label, value, tone }) {
    const toneClasses = {
        primary: 'bg-brand-primary',
        secondary: 'bg-brand-secondary',
        accent: 'bg-brand-accent',
        danger: 'bg-red-500',
    };

    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <div className={`mb-5 h-2 w-16 rounded-full ${toneClasses[tone] ?? toneClasses.primary}`} />
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className="mt-2 text-3xl font-black text-app-text">{value}</p>
        </div>
    );
}

function FundComparison({ row }) {
    const max = Math.max(Number(row.estimated_total ?? 0), Number(row.actual_total ?? 0), 1);

    return (
        <div className="glass-tile p-4">
            <div className="mb-3 flex justify-between gap-4">
                <h3 className="font-black">{row.fund_type}</h3>
                <span className={`text-sm font-black ${Number(row.variance) > 0 ? 'text-red-600 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-200'}`}>
                    {formatMoney(row.variance)}
                </span>
            </div>
            <Bar label="Estimado" value={formatMoney(row.estimated_total)} width={(Number(row.estimated_total ?? 0) / max) * 100} className="bg-brand-primary" />
            <Bar label="Real" value={formatMoney(row.actual_total)} width={(Number(row.actual_total ?? 0) / max) * 100} className="bg-brand-secondary" />
        </div>
    );
}

function Bar({ label, value, width, className }) {
    return (
        <div className="mt-3">
            <div className="mb-1 flex justify-between gap-4 text-sm font-bold">
                <span>{label}</span>
                <span className="text-app-muted">{value}</span>
            </div>
            <div className="h-3 overflow-hidden rounded-full bg-white/35 dark:bg-white/10">
                <div className={`h-full rounded-full ${className}`} style={{ width: `${Math.max(Math.min(width, 100), 4)}%` }} />
            </div>
        </div>
    );
}

function CoverageChart({ coverage }) {
    const withReceipt = Number(coverage.with_receipt ?? 0);
    const withoutReceipt = Number(coverage.without_receipt ?? 0);
    const total = Math.max(withReceipt + withoutReceipt, 1);

    return (
        <div className="space-y-4">
            <Bar label="Con comprobante" value={withReceipt} width={(withReceipt / total) * 100} className="bg-brand-secondary" />
            <Bar label="Sin comprobante" value={withoutReceipt} width={(withoutReceipt / total) * 100} className="bg-red-500" />
        </div>
    );
}

function InvoiceStatusRow({ row }) {
    return (
        <div className="glass-tile p-4">
            <div className="flex items-center justify-between gap-4">
                <div>
                    <p className="font-black">{invoiceLabels[row.status] ?? row.status}</p>
                    <p className="text-sm text-app-muted">{formatMoney(row.total)}</p>
                </div>
                <span className="flex h-10 min-w-10 items-center justify-center rounded-2xl bg-white/35 px-3 text-sm font-black dark:bg-white/10">
                    {row.count}
                </span>
            </div>
        </div>
    );
}

function cleanParams(form) {
    return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}
