import InputLabel from '@/Components/InputLabel';
import LiquidSelect from '@/Components/Glass/LiquidSelect';
import LiquidDateInput from '@/Components/Glass/LiquidDateInput';
import { formatUsd as formatMoney, moneySign } from '@/Utils/money';
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

export default function ReportIndex({ filters = {}, summary = {}, byProject = [], byFund = [], creditAccounts = [], nextCursor = null }) {
    const [form, setForm] = useState({
        year: filters.year ?? new Date().getFullYear(),
        basis: filters.basis ?? 'case_lifetime',
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
        setForm({ year: new Date().getFullYear(), basis: 'case_lifetime' });
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
                <Link href="/accounting/control" className="glass-button px-4">Ver control anual consolidado</Link>
                <form onSubmit={applyFilters} className="glass-panel rounded-[2rem] p-5">
                    <div className="grid gap-4 md:grid-cols-[1fr_1.3fr_auto] md:items-end">
                        <div>
                            <InputLabel htmlFor="report-year" className="text-sm font-bold text-app-muted">Año</InputLabel>
                            <input id="report-year" type="number" min="2000" max="2100" value={form.year}
                                onChange={(event) => setForm((current) => ({ ...current, year: event.target.value }))}
                                className="ios-field mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel htmlFor="report-basis" className="text-sm font-bold text-app-muted">Base de cálculo</InputLabel>
                            <LiquidSelect id="report-basis" value={form.basis}
                                onChange={(event) => setForm((current) => ({ ...current, basis: event.target.value }))}
                                className="ios-field mt-1 block w-full">
                                <option value="case_lifetime">Vida del expediente</option>
                                <option value="period_cash">Cobros del año</option>
                            </LiquidSelect>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button type="submit" className="glass-button gap-2">
                                <SvgIcon name="search" />
                                <span>Filtrar</span>
                            </button>
                            <button type="button" className="glass-button" onClick={clearFilters}>
                                Limpiar
                            </button>
                            <a href={route('reports.export', exportParams)} className="glass-button gap-2">
                                <SvgIcon name="download" />
                                <span>CSV</span>
                            </a>
                        </div>
                    </div>
                </form>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <MetricCard label="Ingreso confirmado" value={formatMoney(summary.income)} tone="primary" />
                    <MetricCard label="Gasto Final" value={formatMoney(summary.operating_total)} tone="secondary" />
                    <MetricCard label="Diferencia" value={formatMoney(summary.difference)} tone={moneySign(summary.difference) === -1 ? 'danger' : 'secondary'} />
                    <MetricCard label="Crédito disponible" value={formatMoney(summary.credit)} tone="accent" />
                </section>

                <section className="grid gap-6 xl:grid-cols-[1fr_0.85fr]">
                    <div className="glass-panel rounded-[2rem] p-5">
                        <h2 className="text-xl font-black">Gasto Final por fondo</h2>
                        <div className="mt-5 space-y-5">
                            {byFund.map((row) => (
                                <FundComparison key={row.fund_type} row={row} />
                            ))}
                        </div>
                    </div>

                    <div className="glass-panel rounded-[2rem] p-5">
                        <h2 className="text-xl font-black">Alcance del reporte</h2>
                        <p className="mt-3 text-sm text-app-muted">Los importes agrupan los expedientes del año y la base de cálculo seleccionados. Los valores pendientes se muestran como tales.</p>
                        <p className="mt-3 text-sm font-semibold">Expedientes: {summary.case_count ?? 0} · Grupos pendientes: {summary.pending_groups ?? 0}</p>
                        <p className="mt-3 text-sm text-app-muted">El crédito pertenece a la cuenta del equipo y no se distribuye entre proyectos. En CSV aparece una vez por cuenta.</p>
                        {creditAccounts.map(account => <p key={account.id} className="text-sm">Cuenta {account.id} · Equipo {account.team_id}: {formatMoney(account.balance)}</p>)}
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[1fr_0.85fr]">
                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <h2 className="text-xl font-black">Resultado por proyecto</h2>
                            <p className="text-sm text-app-muted">{byProject.length} grupos con movimiento</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Proyecto / equipo</th>
                                        <th className="px-5 py-4 text-right">Ingreso</th>
                                        <th className="px-5 py-4 text-right">Gasto Final</th>
                                        <th className="px-5 py-4 text-right">Diferencia</th>
                                        <th className="px-5 py-4 text-right">Cuenta de crédito compartida</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {byProject.map((row) => (
                                        <tr key={row.group_key} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-black">Proyecto {row.project_id}</div>
                                                <div className="text-xs text-app-muted">Equipo {row.team_id}</div>
                                            </td>
                                            <td className="px-5 py-4 text-right">{formatMoney(row.income)}</td>
                                            <td className="px-5 py-4 text-right">{formatMoney(row.operating_total)}</td>
                                            <td className={`px-5 py-4 text-right font-black ${moneySign(row.difference) === -1 ? 'text-red-600 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-200'}`}>
                                                {formatMoney(row.difference)}
                                            </td>
                                            <td className="px-5 py-4 text-right">{row.credit_account_id ? `Cuenta ${row.credit_account_id}` : 'Sin cuenta'}</td>
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
                        <h2 className="text-xl font-black">Detalle del cálculo</h2>
                        <div className="mt-5 space-y-4">
                            <p className="text-sm text-app-muted">{form.basis === 'period_cash' ? 'Se incluyen cobros registrados en el año seleccionado.' : 'Se incluyen expedientes vinculados al año seleccionado.'}</p>
                            <p className="text-sm text-app-muted">Para revisar cada expediente, abre el control anual consolidado.</p>
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
            <InputLabel className="text-sm font-bold text-app-muted">{label}</InputLabel>
            <LiquidDateInput
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
    return <div className="glass-tile p-4">
        <h3 className="font-black">{row.fund_type}</h3>
        <p className="money mt-3 text-lg font-semibold">{formatMoney(row.actual_total)}</p>
    </div>;
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
