import CursorPager from '@/Components/CursorPager';
import FinancialImportPanel from '@/Components/FinancialImportPanel';
import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import WorkflowGuideCard from '@/Components/WorkflowGuideCard';
import { workflows } from '@/Content/workflows';
import { formatUsd } from '@/Utils/money';

const states = [
    { id: 'pending', label: 'Pendiente' },
    { id: 'reconciled', label: 'Reconciliado' },
    { id: 'flagged', label: 'Observado' },
];

export default function AccountingIndex({ invoices = { data: [] }, summary = {}, filters = {}, importPreview = null }) {
    const { auth, flash = {} } = usePage().props;
    const rows = Array.isArray(invoices) ? invoices : invoices.data ?? [];
    return <AuthenticatedLayout header={<h1 className="text-3xl font-semibold">Contabilidad y conciliación</h1>}>
        <Head title="Contabilidad" />
        <div className="space-y-5">
            <WorkflowGuideCard guideKey="accounting" userId={auth.user.id} title="Revisión de descuadres" steps={workflows.accounting} />
            <Link href="/accounting/control" className="glass-button px-5">Abrir control anual y tesorería</Link>
            {flash.success && <p role="status" className="glass-tile p-3">{flash.success}</p>}
            {flash.error && <p role="alert" className="glass-tile p-3 text-red-700">{flash.error}</p>}
            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen del filtro completo">
                {[['count','Expedientes'],['total_dr','DR'],['total_wodr','WODR'],['grand_total','Total documental']].map(([key,label]) =>
                    <div key={key} className="glass-panel p-4"><p>{label}</p><p className="money mt-2 text-xl font-semibold">{key === 'count' ? summary[key] ?? 'Pendiente' : formatUsd(summary[key])}</p></div>)}
            </section>
            <div className="glass-panel p-5">
                <h2 className="text-lg font-semibold">Filtrar por estado</h2>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Link href={route('accounting.index')} className="glass-button px-4" aria-current={!filters.status ? 'page' : undefined}>Todos</Link>
                    {states.map((state) => <Link key={state.id} href={route('accounting.index', { status: state.id })}
                        aria-current={filters.status === state.id ? 'page' : undefined} className="glass-button px-4">{state.label}</Link>)}
                </div>
            </div>
            <FinancialImportPanel key={auth.user.id} preview={importPreview} />
            <section className="glass-panel p-3">
                <h2 className="p-3 text-lg font-semibold">Invoices para revisión</h2>
                <div className="financial-scroll" tabIndex={0} role="region" aria-label="Invoices contables">
                    <table className="financial-table"><caption className="text-left">Página de invoices; montos en USD</caption>
                        <thead><tr><th scope="col">Invoice</th><th scope="col">Proyecto</th><th scope="col">Total</th><th scope="col">Balance documental</th><th scope="col">Estado</th><th scope="col">Acción</th></tr></thead>
                        <tbody>{rows.map((invoice) => <tr key={invoice.id}>
                            <th scope="row">{invoice.code}</th><td>{invoice.trip_phase?.project?.code ?? 'Pendiente'}</td>
                            <td className="money">{formatUsd(invoice.grand_total)}</td>
                            <td className="money">{formatUsd(invoice.balance_due ?? invoice.balance_conciliation)}</td>
                            <td>{invoice.accounting_status ?? 'Pendiente'}</td>
                            <td>{invoice.urls?.review && <Link href={invoice.urls.review} className="glass-button px-3">Revisar</Link>}</td>
                        </tr>)}
                        {rows.length === 0 && <tr><td colSpan={6}>Sin invoices para este filtro.</td></tr>}</tbody>
                    </table>
                </div>
                <CursorPager page={invoices} label="documentos contables" />
            </section>
        </div>
    </AuthenticatedLayout>;
}
