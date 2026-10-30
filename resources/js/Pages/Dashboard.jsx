import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatUsd, moneySign } from '@/Utils/money';

const metricLabels = {
    open_invoices: 'Invoices abiertos',
    expenses_without_receipts: 'Gastos sin recibo',
    receipts: 'Comprobantes',
    active_trips: 'Viajes activos',
};
const financialLabels = {
    estimated_total: 'Estimado',
    real_total: 'Gasto real',
    variance: 'Variación',
    invoice_total: 'Total documental',
    income_real: 'Ingreso confirmado',
    credit_confirmed: 'Crédito confirmado',
    balance_conciliation: 'Balance documental',
};

export default function Dashboard(props) {
    const { metrics, financials, invoiceStatus, accountingStatus, upcomingTrips, attentionItems, recentActivity, systemRules } = props;
    return <AuthenticatedLayout header={<h1 className="text-3xl font-semibold">Panel de control financiero</h1>}>
        <Head title="Panel" />
        <div className="space-y-5">
            {metrics && Object.keys(metrics).length > 0 && <section aria-label="Indicadores de operaciones" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {Object.entries(metricLabels).filter(([key]) => Object.hasOwn(metrics, key)).map(([key,label]) =>
                    <div key={key} className="glass-panel p-4"><h2 className="text-sm font-semibold">{label}</h2><p className="mt-2 text-2xl font-bold">{metrics[key]}</p></div>)}
            </section>}
            {financials && <section className="glass-panel p-5">
                <h2 className="text-lg font-semibold">Resumen financiero autorizado</h2>
                <dl className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {Object.entries(financialLabels).filter(([key]) => Object.hasOwn(financials, key)).map(([key,label]) =>
                        <div key={key} className="glass-tile p-3"><dt>{label}</dt><dd className={`money mt-2 text-lg font-semibold ${key === 'variance' && moneySign(financials[key]) === 1 ? 'text-red-700 dark:text-red-200' : ''}`}>{formatUsd(financials[key])}</dd></div>)}
                </dl>
            </section>}
            {(invoiceStatus || accountingStatus) && <div className="grid gap-4 lg:grid-cols-2">
                {invoiceStatus && <section className="glass-panel p-5"><h2 className="font-semibold">Invoices por estado</h2><ul className="mt-3 space-y-2">{invoiceStatus.map((row) =>
                    <li key={row.status} className="glass-tile flex justify-between p-3"><span>{row.status}</span><span>{row.count}</span></li>)}</ul></section>}
                {accountingStatus && <section className="glass-panel p-5"><h2 className="font-semibold">Revisión contable</h2><ul className="mt-3 space-y-2">{accountingStatus.map((row) =>
                    <li key={row.status} className="glass-tile flex justify-between p-3"><span>{row.status}</span><span>{row.count}</span></li>)}</ul></section>}
            </div>}
            {attentionItems && <section className="glass-panel p-5"><h2 className="font-semibold">Atención requerida</h2><ul className="mt-3 grid gap-3 md:grid-cols-2">{attentionItems.map((item) =>
                <li key={item.label}><Link href={route(item.route)} className="glass-tile flex justify-between p-3"><span>{item.label}</span><span>{item.value}</span></Link></li>)}</ul></section>}
            {upcomingTrips && <section className="glass-panel p-5"><h2 className="font-semibold">Próximos viajes</h2><ul className="mt-3 grid gap-3 md:grid-cols-2">{upcomingTrips.map((trip) =>
                <li key={trip.id} className="glass-tile p-3"><p>{trip.project_name ?? trip.project_code ?? 'Proyecto pendiente'}</p><p className="text-sm">{trip.phase} · {trip.starts_on ?? 'Fecha pendiente'}</p></li>)}</ul></section>}
            {recentActivity && <section className="glass-panel p-5"><h2 className="font-semibold">Actividad reciente</h2><ul className="mt-3 space-y-2">{recentActivity.map((row) =>
                <li key={row.id} className="glass-tile p-3">{row.action} · {row.created_at ?? 'Fecha pendiente'}</li>)}</ul></section>}
            {systemRules && <section className="glass-panel p-5"><h2 className="font-semibold">Reglas del sistema</h2>
                <p>Bloqueo de Final: {systemRules.lockFinalInvoiceByDefault ? 'Activo' : 'Según expediente'}</p></section>}
        </div>
    </AuthenticatedLayout>;
}