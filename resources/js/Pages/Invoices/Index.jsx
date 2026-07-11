import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, router, useForm, usePage } from '@inertiajs/react';
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

export default function InvoiceIndex({ invoices = [], tripPhases = [], contacts = [], phaseTotals = {}, settings = {} }) {
    const { flash } = usePage().props;
    const [phaseFilter, setPhaseFilter] = useState(tripPhases[0]?.id ?? '');
    const [editingInvoice, setEditingInvoice] = useState(null);

    const form = useForm({
        trip_phase_id: phaseFilter,
        contact_person_id: '',
        type: 'IC',
        stage: 'initial',
    });

    const editForm = useForm({
        contact_person_id: '',
        status: 'draft',
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

    const summary = useMemo(() => summarize(visibleInvoices), [visibleInvoices]);

    const submit = (event) => {
        event.preventDefault();

        form.post(route('invoices.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('contact_person_id'),
        });
    };

    const startEdit = (invoice) => {
        setEditingInvoice(invoice);
        editForm.clearErrors();
        editForm.setData({
            contact_person_id: invoice.contact_person_id ?? '',
            status: invoice.status,
        });
    };

    const updateInvoice = (event) => {
        event.preventDefault();

        if (!editingInvoice) {
            return;
        }

        editForm.patch(route('invoices.update', editingInvoice.id), {
            preserveScroll: true,
            onSuccess: () => setEditingInvoice(null),
        });
    };

    const approve = (invoice) => {
        router.post(route('invoices.approve', invoice.id), {}, { preserveScroll: true });
    };

    const generatePdf = (invoice) => {
        router.post(route('invoices.pdf.store', invoice.id), {}, { preserveScroll: true });
    };

    const destroy = (invoice) => {
        if (!window.confirm(`Eliminar invoice "${invoice.code}"?`)) {
            return;
        }

        router.delete(route('invoices.destroy', invoice.id), { preserveScroll: true });
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

                <div className="grid gap-4 md:grid-cols-4">
                    <MetricCard label="Total general" value={formatMoney(summary.grandTotal)} />
                    <MetricCard label="DR" value={formatMoney(summary.totalDr)} />
                    <MetricCard label="WODR" value={formatMoney(summary.totalWodr)} />
                    <MetricCard label="Bloqueados" value={summary.lockedCount} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[410px_1fr]">
                    <div className="space-y-6">
                        <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                            <div>
                                <h2 className="text-xl font-black">Nuevo invoice</h2>
                                <p className="mt-2 text-sm leading-6 text-app-muted">
                                    IC y MAT se generan como invoices separados. Los totales salen de gastos reales.
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
                                    <AmountRow label="DR" value={selectedTotals.total_dr} />
                                    <AmountRow label="WODR" value={selectedTotals.total_wodr} />
                                    <AmountRow label="Total general" value={selectedTotals.grand_total} strong />
                                    <AmountRow label="Credito equipo" value={selectedTotals.team_credit} />
                                    <AmountRow label="Balance conciliacion" value={selectedTotals.balance_conciliation} strong />
                                </div>

                                {form.data.stage === 'final' && settings.lockFinalInvoiceByDefault && (
                                    <div className="rounded-2xl border border-amber-400/30 bg-amber-400/15 px-4 py-3 text-sm font-bold text-app-text">
                                        El final invoice se bloqueara al crearlo por configuracion del sistema.
                                    </div>
                                )}
                            </div>

                            <div className="mt-6">
                                <PrimaryButton disabled={form.processing || tripPhases.length === 0}>
                                    Crear invoice
                                </PrimaryButton>
                            </div>
                        </form>

                        {editingInvoice && (
                            <form onSubmit={updateInvoice} className="glass-panel rounded-[2rem] p-5">
                                <h2 className="text-xl font-black">Editar invoice</h2>
                                <p className="mt-1 text-sm font-semibold text-app-muted">{editingInvoice.code}</p>

                                <div className="mt-5 space-y-4">
                                    <SelectField
                                        id="edit_contact_person_id"
                                        label="Contacto"
                                        value={editForm.data.contact_person_id}
                                        options={contacts.map((contact) => ({ id: contact.id, name: contactLabel(contact) }))}
                                        error={editForm.errors.contact_person_id}
                                        emptyLabel="Sin contacto"
                                        onChange={(value) => editForm.setData('contact_person_id', value)}
                                    />
                                    <SelectField
                                        id="edit_status"
                                        label="Estado"
                                        value={editForm.data.status}
                                        options={statusOptions}
                                        error={editForm.errors.status}
                                        onChange={(value) => editForm.setData('status', value)}
                                    />
                                </div>

                                <div className="mt-6 flex flex-wrap gap-3">
                                    <PrimaryButton disabled={editForm.processing}>Guardar</PrimaryButton>
                                    <button type="button" className="glass-button" onClick={() => setEditingInvoice(null)}>
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                            <div>
                                <h2 className="text-xl font-black">Invoices registrados</h2>
                                <p className="text-sm text-app-muted">{visibleInvoices.length} de {invoices.length} invoices</p>
                            </div>
                            <select
                                value={phaseFilter}
                                onChange={(event) => changePhaseFilter(event.target.value)}
                                className="rounded-xl border-app-border bg-white/70 text-sm font-bold text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                            >
                                <option value="">Todas las fases</option>
                                {tripPhases.map((phase) => (
                                    <option key={phase.id} value={phase.id}>{phaseLabel(phase)}</option>
                                ))}
                            </select>
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
                                                        <IconButton icon="edit" label="Editar invoice" type="button" onClick={() => startEdit(invoice)} />
                                                    )}
                                                    {invoice.status === 'draft' && (
                                                        <IconButton icon="check" label="Aprobar invoice" type="button" onClick={() => approve(invoice)} />
                                                    )}
                                                    {!invoice.locked_at && (
                                                        <IconButton icon="trash" label="Eliminar invoice" type="button" variant="danger" onClick={() => destroy(invoice)} />
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
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function AmountRow({ label, value, strong = false }) {
    return (
        <div className={`flex items-center justify-between gap-4 ${strong ? 'font-black' : 'font-bold text-app-muted'}`}>
            <span>{label}</span>
            <span>{formatMoney(value)}</span>
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

function summarize(invoices) {
    return invoices.reduce((carry, invoice) => ({
        totalDr: carry.totalDr + Number(invoice.total_dr ?? 0),
        totalWodr: carry.totalWodr + Number(invoice.total_wodr ?? 0),
        grandTotal: carry.grandTotal + Number(invoice.grand_total ?? 0),
        lockedCount: carry.lockedCount + (invoice.locked_at ? 1 : 0),
    }), { totalDr: 0, totalWodr: 0, grandTotal: 0, lockedCount: 0 });
}

function emptyTotals() {
    return {
        total_dr: 0,
        total_wodr: 0,
        grand_total: 0,
        team_credit: 0,
        balance_conciliation: 0,
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

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}
