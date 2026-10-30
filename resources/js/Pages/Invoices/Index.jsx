import CursorPager from '@/Components/CursorPager';
import LiquidSelect from '@/Components/Glass/LiquidSelect';
import FinancialActionSheet from '@/Components/FinancialActionSheet';
import WorkflowGuideCard from '@/Components/WorkflowGuideCard';
import HelpTooltip from '@/Components/HelpTooltip';
import { financialHelp } from '@/Content/financialHelp';
import { workflows } from '@/Content/workflows';
import { formatUsd as formatMoney, moneySign } from '@/Utils/money';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

const invoiceTypes = [
    { id: 'IC', name: 'IC' },
    { id: 'MAT', name: 'MAT' },
];

const invoiceStages = [
    { id: 'initial', name: 'Inicial' },
    { id: 'final', name: 'Final' },
];

const statusOptions = [
    { id: 'draft', name: 'Borrador' },
    { id: 'approved', name: 'Aprobado' },
    { id: 'sent', name: 'Enviado' },
    { id: 'paid', name: 'Pagado' },
    { id: 'void', name: 'Anulado' },
];

export default function InvoiceIndex({ invoices: invoicePage = [], tripPhases = [], contacts = [], phaseTotals = {}, settings = {}, summary: serverSummary = null }) {
    const invoices = Array.isArray(invoicePage) ? invoicePage : invoicePage.data ?? [];
    const { flash, auth } = usePage().props;
    const [phaseFilter, setPhaseFilter] = useState(tripPhases[0]?.id ?? '');
    const [pendingAction, setPendingAction] = useState(null);
    const [busy, setBusy] = useState(false);
    const [voidReason, setVoidReason] = useState('');

    const form = useForm({
        trip_phase_id: phaseFilter,
        contact_person_id: '',
        type: 'IC',
        stage: 'initial',
    });


    const selectedTotals = phaseTotals[String(form.data.trip_phase_id)] ?? emptyTotals();

    const visibleInvoices = useMemo(() => {
        if (!phaseFilter) {
            return invoices;
        }

        return invoices.filter((invoice) => String(invoice.trip_phase_id) === String(phaseFilter));
    }, [invoices, phaseFilter]);
    const searchableInvoiceText = useCallback((invoice) => [
        invoice.code,
        invoice.type,
        invoice.stage,
        invoice.status,
        invoice.trip_phase?.project?.code,
        invoice.trip_phase?.phase,
        invoice.contact_person?.full_name,
        invoice.contact_person?.email,
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(visibleInvoices, searchableInvoiceText, 10);

    const summary = serverSummary && String(serverSummary.trip_phase_id ?? '') === String(phaseFilter)
        ? serverSummary : { grandTotal: null, totalDr: null, totalWodr: null };
    const lockedVisible = visibleInvoices.filter((invoice) => invoice.locked_at).length;

    const submit = (event) => {
        event.preventDefault();

        form.post(route('invoices.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('contact_person_id'),
        });
    };

    const confirmAction = () => {
        if (!pendingAction || busy) return;
        if (pendingAction.type === 'void' && !voidReason.trim()) return;
        setBusy(true);
        const routeName = pendingAction.type === 'void' ? 'invoices.void' : pendingAction.type === 'refresh' ? 'invoices.refresh' : 'invoices.approve';
        const url = route(routeName, pendingAction.invoice.id);
        router.post(url, { version: pendingAction.invoice.version, ...(pendingAction.type === 'void' ? { reason: voidReason.trim() } : {}) }, {
            preserveScroll: true,
            onFinish: () => { setBusy(false); setPendingAction(null); setVoidReason(''); },
        });
    };

    const changePhaseFilter = (value) => {
        setPhaseFilter(value);
        form.setData('trip_phase_id', value);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Invoices</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">IC y MAT por fase de viaje</h1>
                </div>
            }
        >
            <Head title="Invoices" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />
                <CursorPager page={invoicePage} label="invoices" />

                <WorkflowGuideCard guideKey="invoices" userId={auth.user.id} title="De Initial a Final" steps={workflows.invoices} />

                <div className="grid gap-4 md:grid-cols-4">
                    <MetricCard label="Total general" value={formatMoney(summary.grandTotal)} />
                    <MetricCard label="DR" value={formatMoney(summary.totalDr)} />
                    <MetricCard label="WODR" value={formatMoney(summary.totalWodr)} />
                    <MetricCard label="Bloqueados visibles" value={lockedVisible} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[410px_1fr]">
                    <div className="space-y-6">
                        <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                            <div>
                                <h2 className="text-xl font-black">Nuevo invoice</h2>
                                <p className="mt-2 text-sm leading-6 text-app-muted">
                                    Initial utiliza el presupuesto estimado aprobado; Final utiliza gastos reales de su etapa.
                                </p>
                            </div>

                            <div className="mt-6 space-y-4">
                                <SelectField
                                    id="trip_phase_id"
                                    label="Fase de viaje"
                                    value={form.data.trip_phase_id}
                                    options={tripPhases.map((phase) => ({ id: phase.id, name: phaseLabel(phase) }))}
                                    error={form.errors.trip_phase_id}
                                    onChange={(value) => form.setData('trip_phase_id', value)}
                                />

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <SelectField
                                        id="type"
                                        label="Tipo"
                                        value={form.data.type}
                                        options={invoiceTypes}
                                        error={form.errors.type}
                                        onChange={(value) => form.setData('type', value)}
                                    />
                                    <SelectField
                                        id="stage"
                                        label="Etapa"
                                        value={form.data.stage}
                                        options={invoiceStages}
                                        error={form.errors.stage}
                                        onChange={(value) => form.setData('stage', value)}
                                    />
                                </div>

                                <SelectField
                                    id="contact_person_id"
                                    label="Contacto"
                                    value={form.data.contact_person_id}
                                    options={contacts.map((contact) => ({ id: contact.id, name: contactLabel(contact) }))}
                                    error={form.errors.contact_person_id}
                                    emptyLabel="Sin contacto"
                                    onChange={(value) => form.setData('contact_person_id', value)}
                                />

                                <div className="grid gap-3 rounded-2xl bg-white/30 p-4 text-sm dark:bg-white/10">
                                    <AmountRow label="DR" value={selectedTotals.total_dr} helpKey="initialDr" />
                                    <AmountRow label="WODR" value={selectedTotals.total_wodr} helpKey="initialWodr" />
                                    <AmountRow label="Total general" value={selectedTotals.grand_total} strong />
                                    <AmountRow label="Crédito equipo" value={selectedTotals.team_credit} helpKey="credit" />
                                    <AmountRow label="Balance documental" value={selectedTotals.balance_conciliation} strong helpKey="balanceDue" />
                                </div>

                                <p className="text-sm">Revisa el borrador antes de aprobar. La aprobación protege el documento y sus importes.</p>
                            </div>

                            <div className="mt-6">
                                <PrimaryButton disabled={form.processing || tripPhases.length === 0}>
                                    Crear invoice
                                </PrimaryButton>
                            </div>
                        </form>

                    </div>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                            <div>
                                <h2 className="text-xl font-black">Invoices registrados</h2>
                                <p className="text-sm text-app-muted">{visibleInvoices.length} de {invoices.length} invoices</p>
                            </div>
                            <LiquidSelect
                                value={phaseFilter}
                                onChange={(event) => changePhaseFilter(event.target.value)}
                                className="rounded-xl border-app-border bg-white/70 text-sm font-bold text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                            >
                                <option value="">Todas las fases</option>
                                {tripPhases.map((phase) => (
                                    <option key={phase.id} value={phase.id}>{phaseLabel(phase)}</option>
                                ))}
                            </LiquidSelect>
                            </div>
                            <TableControls
                                query={table.query}
                                onQueryChange={table.updateQuery}
                                page={table.page}
                                totalPages={table.totalPages}
                                pageSize={table.pageSize}
                                onPageSizeChange={table.updatePageSize}
                                onPageChange={table.setPage}
                                total={visibleInvoices.length}
                                filtered={table.filteredRows.length}
                                placeholder="Buscar invoice"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Código</th>
                                        <th className="px-5 py-4">Fase</th>
                                        <th className="px-5 py-4">Contacto</th>
                                        <th className="px-5 py-4 text-right">DR</th>
                                        <th className="px-5 py-4 text-right">WODR</th>
                                        <th className="px-5 py-4 text-right">Balance</th>
                                        <th className="px-5 py-4">Estado</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {table.paginatedRows.map((invoice) => (
                                        <tr key={invoice.id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-black">{invoice.code}</div>
                                                <div className="text-xs text-app-muted">{invoice.type} / {stageLabel(invoice.stage)}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{invoice.trip_phase?.project?.code ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{invoice.trip_phase?.phase ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{invoice.contact_person?.full_name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{invoice.contact_person?.email ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4 text-right">{formatMoney(invoice.total_dr)}</td>
                                            <td className="px-5 py-4 text-right">{formatMoney(invoice.total_wodr)}</td>
                                            <td className="px-5 py-4 text-right font-black">{formatMoney(invoice.balance_conciliation)}</td>
                                            <td className="px-5 py-4">
                                                <StatusBadge status={invoice.status} locked={Boolean(invoice.locked_at)} />
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton
                                                        icon="file"
                                                        label="Generar PDF"
                                                        type="button"
                                                        onClick={() => generatePdf(invoice)}
                                                    />
                                                    {invoice.pdf_file && (
                                                        <IconButton
                                                            as="a"
                                                            href={route('invoices.pdf.show', invoice.id)}
                                                            icon="download"
                                                            label="Descargar PDF"
                                                        />
                                                    )}
                                                    {!invoice.locked_at && (
                                                        <IconButton icon="edit" label="Actualizar borrador" type="button" onClick={() => setPendingAction({ type: 'refresh', invoice })} />
                                                    )}
                                                    {invoice.status === 'draft' && (
                                                        <IconButton icon="check" label="Aprobar invoice" type="button" onClick={() => setPendingAction({ type: 'approve', invoice })} />
                                                    )}
                                                    {!invoice.locked_at && (
                                                        <IconButton icon="trash" label="Anular invoice" type="button" variant="danger" onClick={() => setPendingAction({ type: 'void', invoice })} />
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="8" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay invoices para este filtro.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
            <FinancialActionSheet open={Boolean(pendingAction)} busy={busy} title={pendingAction?.type === 'void' ? 'Anular invoice' : pendingAction?.type === 'refresh' ? 'Actualizar borrador' : 'Aprobar invoice'}
                description={`${pendingAction?.invoice.code ?? ''} · ${formatMoney(pendingAction?.invoice.grand_total)} · ${pendingAction?.invoice.status ?? ''} → ${pendingAction?.type === 'void' ? 'void' : pendingAction?.type === 'refresh' ? 'draft actualizado' : 'approved'}`}
                confirmLabel={pendingAction?.type === 'void' ? 'Anular' : pendingAction?.type === 'refresh' ? 'Actualizar' : 'Aprobar'}
                confirmDisabled={pendingAction?.type === 'void' && !voidReason.trim()}
                onCancel={() => setPendingAction(null)} onConfirm={confirmAction}>
                {pendingAction?.type === 'void' && <div className="mt-4"><InputLabel htmlFor="void-reason">Motivo de anulación</InputLabel><input id="void-reason" className="ios-field mt-2 w-full" value={voidReason} onChange={(event) => setVoidReason(event.target.value)} maxLength={500} required /></div>}
            </FinancialActionSheet>
        </AuthenticatedLayout>
    );
}

function FlashMessages({ flash }) {
    const error = flash.errors?.invoice?.[0];

    if (!flash.success && !error) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {error && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {error}
                </div>
            )}
        </div>
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

function SelectField({ id, label, value, options, error, onChange, emptyLabel = 'Seleccionar' }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <LiquidSelect
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
            >
                <option value="">{emptyLabel}</option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>{option.name}</option>
                ))}
            </LiquidSelect>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function AmountRow({ label, value, strong = false, helpKey = null }) {
    return (
        <div className={`flex items-center justify-between gap-4 ${strong ? 'font-black' : 'font-bold text-app-muted'}`}>
            <span>{label}{helpKey && <HelpTooltip {...financialHelp[helpKey]} />}</span>
            <span className="money">{formatMoney(value)}</span>
        </div>
    );
}

function StatusBadge({ status, locked }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="w-fit rounded-full bg-white/40 px-3 py-1 text-xs font-black uppercase text-app-text dark:bg-white/10">
                {statusLabel(status)}
            </span>
            {locked && <span className="text-xs font-bold text-amber-700 dark:text-amber-200">Bloqueado</span>}
        </div>
    );
}


function emptyTotals() {
    return {
        total_dr: null,
        total_wodr: null,
        grand_total: null,
        team_credit: null,
        balance_conciliation: null,
    };
}

function phaseLabel(phase) {
    return `${phase.project?.code ?? 'Sin proyecto'} - ${phase.phase} (${phase.team?.name ?? 'Sin equipo'})`;
}

function contactLabel(contact) {
    return `${contact.full_name}${contact.email ? ` - ${contact.email}` : ''}`;
}

function stageLabel(stage) {
    return stage === 'final' ? 'Final' : 'Inicial';
}

function statusLabel(status) {
    return statusOptions.find((option) => option.id === status)?.name ?? status;
}
