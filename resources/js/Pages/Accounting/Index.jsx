import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const accountingStatuses = [
    { id: 'pending', name: 'Pendiente' },
    { id: 'reconciled', name: 'Reconciliado' },
    { id: 'flagged', name: 'Observado' },
];

export default function AccountingIndex({ invoices = { data: [] }, summary = {}, filters = {}, settings = {}, importPreview = null }) {
    const { flash } = usePage().props;
    const [editingInvoice, setEditingInvoice] = useState(null);

    const invoiceRows = invoices.data ?? [];
    const form = useForm({
        accounting_status: 'pending',
        accounting_note: '',
        total_dr: 0,
        total_wodr: 0,
        balance_conciliation: 0,
    });
    const importForm = useForm({
        file: null,
    });
    const applyImportForm = useForm({});

    const previewGrandTotal = useMemo(() => (
        Number(form.data.total_dr || 0) + Number(form.data.total_wodr || 0)
    ), [form.data.total_dr, form.data.total_wodr]);

    const startEdit = (invoice) => {
        setEditingInvoice(invoice);
        form.clearErrors();
        form.setData({
            accounting_status: invoice.accounting_status ?? 'pending',
            accounting_note: invoice.accounting_note ?? '',
            total_dr: invoice.total_dr ?? 0,
            total_wodr: invoice.total_wodr ?? 0,
            balance_conciliation: invoice.balance_conciliation ?? 0,
        });
    };

    const submit = (event) => {
        event.preventDefault();

        if (!editingInvoice) {
            return;
        }

        form.patch(route('accounting.update', editingInvoice.id), {
            preserveScroll: true,
            onSuccess: () => setEditingInvoice(null),
        });
    };

    const previewImport = (event) => {
        event.preventDefault();

        importForm.post(route('accounting-import.preview'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => importForm.reset('file'),
        });
    };

    const applyImport = () => {
        applyImportForm.post(route('accounting-import.apply'), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Contabilidad</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Resumen y conciliacion</h1>
                </div>
            }
        >
            <Head title="Contabilidad" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <div className="grid gap-4 md:grid-cols-5">
                    <MetricCard label="Invoices" value={summary.count} />
                    <MetricCard label="DR" value={formatMoney(summary.total_dr)} />
                    <MetricCard label="WODR" value={formatMoney(summary.total_wodr)} />
                    <MetricCard label="Grand total" value={formatMoney(summary.grand_total)} />
                    <MetricCard label="Balance" value={formatMoney(summary.balance_conciliation)} />
                </div>

                <AccountingCharts summary={summary} />

                <section className="grid gap-6 xl:grid-cols-[410px_1fr]">
                    <div className="space-y-6">
                        <div className="glass-panel rounded-[2rem] p-5">
                            <h2 className="text-xl font-black">Filtro contable</h2>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <FilterLink active={!filters.status} href={route('accounting.index')}>Todos</FilterLink>
                                {accountingStatuses.map((status) => (
                                    <FilterLink
                                        key={status.id}
                                        active={filters.status === status.id}
                                        href={route('accounting.index', { status: status.id })}
                                    >
                                        {status.name} ({summary[status.id]})
                                    </FilterLink>
                                ))}
                            </div>
                            <div className="mt-5 rounded-2xl bg-white/30 px-4 py-3 text-sm font-bold text-app-muted dark:bg-white/10">
                                {settings.accountingCanEditSummary
                                    ? 'Contabilidad puede corregir valores del resumen.'
                                    : 'Contabilidad solo puede visualizar y reconciliar.'}
                            </div>
                        </div>

                        <ImportPanel
                            settings={settings}
                            importPreview={importPreview}
                            importForm={importForm}
                            applyImportForm={applyImportForm}
                            onPreview={previewImport}
                            onApply={applyImport}
                        />

                        {editingInvoice && (
                            <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                                <h2 className="text-xl font-black">Revision contable</h2>
                                <p className="mt-1 text-sm font-semibold text-app-muted">{editingInvoice.code}</p>

                                <div className="mt-5 space-y-4">
                                    <SelectField
                                        id="accounting_status"
                                        label="Estado contable"
                                        value={form.data.accounting_status}
                                        options={accountingStatuses}
                                        error={form.errors.accounting_status}
                                        onChange={(value) => form.setData('accounting_status', value)}
                                    />

                                    <div>
                                        <InputLabel htmlFor="accounting_note" value="Nota contable" />
                                        <textarea
                                            id="accounting_note"
                                            value={form.data.accounting_note ?? ''}
                                            onChange={(event) => form.setData('accounting_note', event.target.value)}
                                            rows="4"
                                            className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                                        />
                                        <InputError message={form.errors.accounting_note} className="mt-2" />
                                    </div>

                                    {settings.accountingCanEditSummary && (
                                        <div className="space-y-4">
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <TextField
                                                    id="total_dr"
                                                    label="Total DR"
                                                    value={form.data.total_dr}
                                                    error={form.errors.total_dr}
                                                    onChange={(value) => form.setData('total_dr', value)}
                                                />
                                                <TextField
                                                    id="total_wodr"
                                                    label="Total WODR"
                                                    value={form.data.total_wodr}
                                                    error={form.errors.total_wodr}
                                                    onChange={(value) => form.setData('total_wodr', value)}
                                                />
                                            </div>
                                            <TextField
                                                id="balance_conciliation"
                                                label="Balance conciliacion"
                                                value={form.data.balance_conciliation}
                                                error={form.errors.balance_conciliation}
                                                onChange={(value) => form.setData('balance_conciliation', value)}
                                            />
                                            <div className="rounded-2xl bg-white/30 px-4 py-3 text-sm font-black dark:bg-white/10">
                                                Grand total recalculado: {formatMoney(previewGrandTotal)}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className="mt-6 flex flex-wrap gap-3">
                                    <PrimaryButton disabled={form.processing}>Guardar revision</PrimaryButton>
                                    <button type="button" className="glass-button" onClick={() => setEditingInvoice(null)}>
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <h2 className="text-xl font-black">Invoices para conciliacion</h2>
                            <p className="text-sm text-app-muted">Paginacion por cursor para mantener respuesta estable con alto volumen.</p>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Invoice</th>
                                        <th className="px-5 py-4">Proyecto</th>
                                        <th className="px-5 py-4 text-right">Grand total</th>
                                        <th className="px-5 py-4 text-right">Balance</th>
                                        <th className="px-5 py-4">Estado contable</th>
                                        <th className="px-5 py-4">Revision</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {invoiceRows.map((invoice) => (
                                        <tr key={invoice.id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-black">{invoice.code}</div>
                                                <div className="text-xs text-app-muted">{invoice.type} / {invoice.stage}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{invoice.trip_phase?.project?.code ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{invoice.trip_phase?.team?.name ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4 text-right font-black">{formatMoney(invoice.grand_total)}</td>
                                            <td className="px-5 py-4 text-right">{formatMoney(invoice.balance_conciliation)}</td>
                                            <td className="px-5 py-4">
                                                <AccountingBadge status={invoice.accounting_status} />
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{invoice.accounting_reviewed_by?.name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{formatDate(invoice.accounting_reviewed_at)}</div>
                                            </td>
                                            <td className="px-5 py-4 text-right">
                                                <IconButton icon="edit" label="Revisar invoice" type="button" onClick={() => startEdit(invoice)} />
                                            </td>
                                        </tr>
                                    ))}
                                    {invoiceRows.length === 0 && (
                                        <tr>
                                            <td colSpan="7" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                No hay invoices para este filtro.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-white/30 px-5 py-4 dark:border-white/10">
                            <span className="text-sm font-bold text-app-muted">
                                {invoiceRows.length} registros en esta pagina
                            </span>
                            <div className="flex gap-2">
                                {invoices.prev_page_url && (
                                    <Link href={invoices.prev_page_url} className="glass-button px-3 py-2 text-xs">Anterior</Link>
                                )}
                                {invoices.next_page_url && (
                                    <Link href={invoices.next_page_url} className="glass-button px-3 py-2 text-xs">Siguiente</Link>
                                )}
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function FlashMessages({ flash }) {
    const error = flash.errors?.accounting_status?.[0]
        ?? flash.errors?.total_dr?.[0]
        ?? flash.errors?.total_wodr?.[0]
        ?? flash.errors?.balance_conciliation?.[0]
        ?? flash.errors?.file?.[0];

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

function ImportPanel({ settings, importPreview, importForm, applyImportForm, onPreview, onApply }) {
    const rows = importPreview?.rows ?? [];
    const previewSummary = importPreview?.summary ?? null;

    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-black">Importar resumen contable</h2>
                    <p className="mt-1 text-sm text-app-muted">Columnas esperadas: code, accounting_status, total_dr, total_wodr, balance_conciliation, accounting_note.</p>
                </div>
                <span className="rounded-2xl bg-white/35 px-3 py-1 text-xs font-black uppercase text-app-muted dark:bg-white/10">
                    CSV / XLSX
                </span>
            </div>

            <div className="mt-4 rounded-2xl bg-white/30 px-4 py-3 text-sm font-bold text-app-muted dark:bg-white/10">
                {settings.accountingCanEditSummary
                    ? 'Modo activo: importar estados y corregir valores contables.'
                    : 'Modo activo: importar estado/nota; los valores del archivo solo se previsualizan.'}
            </div>

            <form onSubmit={onPreview} className="mt-5 space-y-4">
                <div>
                    <InputLabel htmlFor="accounting_import_file" value="Archivo contable" />
                    <input
                        id="accounting_import_file"
                        type="file"
                        accept=".csv,.txt,.xlsx"
                        onChange={(event) => importForm.setData('file', event.target.files?.[0] ?? null)}
                        className="ios-field mt-1 block w-full"
                    />
                    <InputError message={importForm.errors.file} className="mt-2" />
                </div>

                <PrimaryButton disabled={importForm.processing || !importForm.data.file}>
                    Previsualizar
                </PrimaryButton>
            </form>

            {previewSummary && (
                <div className="mt-6 space-y-4">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <ImportMetric label="Validas" value={previewSummary.valid} />
                        <ImportMetric label="Invalidas" value={previewSummary.invalid} />
                        <ImportMetric label="Total" value={previewSummary.total} />
                    </div>

                    <div className="max-h-72 overflow-auto rounded-2xl border border-white/30 dark:border-white/10">
                        <table className="min-w-full divide-y divide-white/30 text-sm dark:divide-white/10">
                            <thead className="bg-white/25 text-left text-xs font-black uppercase tracking-wide text-app-muted dark:bg-white/5">
                                <tr>
                                    <th className="px-4 py-3">Fila</th>
                                    <th className="px-4 py-3">Invoice</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3 text-right">DR</th>
                                    <th className="px-4 py-3 text-right">WODR</th>
                                    <th className="px-4 py-3">Resultado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/20 dark:divide-white/10">
                                {rows.slice(0, 20).map((row) => (
                                    <tr key={`${row.row}-${row.code}`}>
                                        <td className="px-4 py-3 font-bold">{row.row}</td>
                                        <td className="px-4 py-3 font-black">{row.code}</td>
                                        <td className="px-4 py-3">{row.accounting_status ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">{formatMoney(row.total_dr)}</td>
                                        <td className="px-4 py-3 text-right">{formatMoney(row.total_wodr)}</td>
                                        <td className="px-4 py-3">
                                            {row.can_apply ? (
                                                <span className="rounded-full bg-emerald-400/20 px-3 py-1 text-xs font-black text-emerald-800 dark:text-emerald-100">Lista</span>
                                            ) : (
                                                <span className="text-xs font-bold text-red-700 dark:text-red-200">{row.errors.join(' ')}</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length > 20 && (
                        <p className="text-xs font-bold text-app-muted">Se muestran las primeras 20 filas de la previsualizacion.</p>
                    )}

                    <PrimaryButton disabled={applyImportForm.processing || previewSummary.valid === 0} onClick={onApply}>
                        Aplicar importacion
                    </PrimaryButton>
                </div>
            )}
        </div>
    );
}

function ImportMetric({ label, value }) {
    return (
        <div className="rounded-2xl bg-white/30 px-4 py-3 dark:bg-white/10">
            <p className="text-xs font-bold uppercase text-app-muted">{label}</p>
            <p className="mt-1 text-2xl font-black">{value}</p>
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

function FilterLink({ active, href, children }) {
    return (
        <Link
            href={href}
            className={`rounded-2xl px-4 py-2 text-sm font-black transition ${
                active ? 'bg-brand-primary text-white' : 'bg-white/30 text-app-muted hover:text-app-text dark:bg-white/10'
            }`}
        >
            {children}
        </Link>
    );
}

function AccountingCharts({ summary }) {
    const statusRows = [
        { label: 'Pendientes', value: summary.pending, className: 'bg-amber-400' },
        { label: 'Reconciliados', value: summary.reconciled, className: 'bg-emerald-400' },
        { label: 'Observados', value: summary.flagged, className: 'bg-red-400' },
    ];
    const totalStatuses = Math.max(statusRows.reduce((total, row) => total + Number(row.value ?? 0), 0), 1);
    const maxAmount = Math.max(Number(summary.total_dr ?? 0), Number(summary.total_wodr ?? 0), Number(summary.balance_conciliation ?? 0), 1);
    const amountRows = [
        { label: 'DR', value: summary.total_dr, className: 'bg-brand-primary' },
        { label: 'WODR', value: summary.total_wodr, className: 'bg-brand-secondary' },
        { label: 'Balance', value: summary.balance_conciliation, className: 'bg-brand-accent' },
    ];

    return (
        <section className="grid gap-4 lg:grid-cols-2">
            <div className="glass-panel rounded-[2rem] p-5">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-black">Estado contable</h2>
                        <p className="text-sm text-app-muted">Distribución por reconciliación</p>
                    </div>
                    <span className="rounded-2xl bg-white/35 px-3 py-1 text-sm font-black dark:bg-white/10">
                        {summary.count}
                    </span>
                </div>
                <div className="mt-5 space-y-4">
                    {statusRows.map((row) => (
                        <ChartBar
                            key={row.label}
                            label={row.label}
                            value={row.value}
                            width={(Number(row.value ?? 0) / totalStatuses) * 100}
                            className={row.className}
                        />
                    ))}
                </div>
            </div>

            <div className="glass-panel rounded-[2rem] p-5">
                <h2 className="text-lg font-black">Montos conciliados</h2>
                <p className="text-sm text-app-muted">Comparación de DR, WODR y balance</p>
                <div className="mt-5 space-y-4">
                    {amountRows.map((row) => (
                        <ChartBar
                            key={row.label}
                            label={row.label}
                            value={formatMoney(row.value)}
                            width={(Number(row.value ?? 0) / maxAmount) * 100}
                            className={row.className}
                        />
                    ))}
                </div>
            </div>
        </section>
    );
}

function ChartBar({ label, value, width, className }) {
    return (
        <div>
            <div className="mb-2 flex items-center justify-between gap-4 text-sm font-bold">
                <span>{label}</span>
                <span className="text-app-muted">{value}</span>
            </div>
            <div className="h-3 overflow-hidden rounded-full bg-white/35 shadow-inner dark:bg-white/10">
                <div
                    className={`h-full rounded-full ${className}`}
                    style={{ width: `${Math.max(Math.min(width, 100), 4)}%` }}
                />
            </div>
        </div>
    );
}

function SelectField({ id, label, value, options, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
            >
                {options.map((option) => (
                    <option key={option.id} value={option.id}>{option.name}</option>
                ))}
            </select>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function TextField({ id, label, value, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <TextInput
                id={id}
                type="number"
                step="0.01"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function AccountingBadge({ status }) {
    const classes = {
        pending: 'bg-amber-400/20 text-amber-800 dark:text-amber-100',
        reconciled: 'bg-emerald-400/20 text-emerald-800 dark:text-emerald-100',
        flagged: 'bg-red-400/20 text-red-800 dark:text-red-100',
    };

    return (
        <span className={`rounded-full px-3 py-1 text-xs font-black uppercase ${classes[status] ?? classes.pending}`}>
            {status ?? 'pending'}
        </span>
    );
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

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
