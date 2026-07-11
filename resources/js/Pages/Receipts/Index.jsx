import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

export default function ReceiptIndex({ actualExpenses = [], receipts = [] }) {
    const { flash } = usePage().props;
    const [editingReceipt, setEditingReceipt] = useState(null);
    const [expenseFilter, setExpenseFilter] = useState(actualExpenses[0]?.id ?? '');

    const form = useForm({
        actual_expense_id: expenseFilter,
        receipt_number: '',
        issued_on: '',
        amount: 0,
        file: null,
    });

    const visibleReceipts = useMemo(() => {
        if (!expenseFilter) {
            return receipts;
        }

        return receipts.filter((receipt) => String(receipt.actual_expense_id) === String(expenseFilter));
    }, [receipts, expenseFilter]);
    const searchableReceiptText = useCallback((receipt) => [
        receipt.storage_file?.original_name,
        receipt.actual_expense?.description,
        receipt.actual_expense?.trip_phase?.project?.code,
        receipt.receipt_number,
        receipt.issued_on,
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(visibleReceipts, searchableReceiptText, 10);

    const selectedExpense = useMemo(
        () => actualExpenses.find((expense) => String(expense.id) === String(expenseFilter)) ?? null,
        [actualExpenses, expenseFilter],
    );

    const summary = useMemo(() => visibleReceipts.reduce((total, receipt) => total + Number(receipt.amount ?? 0), 0), [visibleReceipts]);

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => resetForm(),
        };

        if (editingReceipt) {
            form.patch(route('receipts.update', editingReceipt.id), {
                preserveScroll: true,
                onSuccess: () => resetForm(),
            });
            return;
        }

        form.post(route('receipts.store'), options);
    };

    const edit = (receipt) => {
        setEditingReceipt(receipt);
        setExpenseFilter(receipt.actual_expense_id);
        form.clearErrors();
        form.setData({
            actual_expense_id: receipt.actual_expense_id,
            receipt_number: receipt.receipt_number ?? '',
            issued_on: formatDateInput(receipt.issued_on),
            amount: receipt.amount ?? 0,
            file: null,
        });
    };

    const resetForm = () => {
        setEditingReceipt(null);
        form.clearErrors();
        form.setData({
            actual_expense_id: expenseFilter,
            receipt_number: '',
            issued_on: '',
            amount: 0,
            file: null,
        });
    };

    const destroy = (receipt) => {
        if (!window.confirm(`Eliminar "${receipt.storage_file?.original_name ?? 'comprobante'}"?`)) {
            return;
        }

        router.delete(route('receipts.destroy', receipt.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingReceipt?.id === receipt.id) {
                    resetForm();
                }
            },
        });
    };

    const changeExpenseFilter = (value) => {
        setExpenseFilter(value);
        if (!editingReceipt) {
            form.setData('actual_expense_id', value);
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Comprobantes
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Recibos y archivos soporte
                    </h1>
                </div>
            }
        >
            <Head title="Recibos" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <div className="grid gap-4 md:grid-cols-3">
                    <MetricCard label="Comprobantes" value={visibleReceipts.length} />
                    <MetricCard label="Monto comprobado" value={formatMoney(summary)} />
                    <MetricCard label="Gasto seleccionado" value={selectedExpense ? formatMoney(selectedExpense.real_total) : '-'} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[390px_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingReceipt ? 'Editar metadata' : 'Nuevo comprobante'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Archivos permitidos: PDF, JPG, PNG y WEBP hasta 10 MB.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="actual_expense_id"
                                label="Gasto real"
                                value={form.data.actual_expense_id}
                                options={actualExpenses.map((expense) => ({ id: expense.id, name: expenseLabel(expense) }))}
                                error={form.errors.actual_expense_id}
                                onChange={(value) => form.setData('actual_expense_id', value)}
                            />

                            <TextField
                                id="receipt_number"
                                label="Numero de recibo/factura"
                                value={form.data.receipt_number}
                                error={form.errors.receipt_number}
                                onChange={(value) => form.setData('receipt_number', value)}
                            />

                            <TextField
                                id="issued_on"
                                label="Fecha de emision"
                                type="date"
                                value={form.data.issued_on}
                                error={form.errors.issued_on}
                                onChange={(value) => form.setData('issued_on', value)}
                            />

                            <TextField
                                id="amount"
                                label="Monto"
                                type="number"
                                step="0.01"
                                value={form.data.amount}
                                error={form.errors.amount}
                                onChange={(value) => form.setData('amount', value)}
                            />

                            {!editingReceipt && (
                                <div>
                                    <InputLabel htmlFor="file" value="Archivo" />
                                    <input
                                        id="file"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                        className="mt-1 block w-full rounded-xl border border-app-border bg-white/70 px-3 py-2 text-sm text-app-text shadow-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-primary file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-white dark:bg-white/10"
                                    />
                                    <InputError message={form.errors.file} className="mt-2" />
                                </div>
                            )}
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={form.processing || actualExpenses.length === 0}>
                                {editingReceipt ? 'Guardar cambios' : 'Subir comprobante'}
                            </PrimaryButton>
                            {editingReceipt && (
                                <button type="button" className="glass-button" onClick={resetForm}>
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                                <div>
                                    <h2 className="text-xl font-black">Comprobantes registrados</h2>
                                    <p className="text-sm text-app-muted">{visibleReceipts.length} de {receipts.length} archivos</p>
                                </div>
                                <select
                                    value={expenseFilter}
                                    onChange={(event) => changeExpenseFilter(event.target.value)}
                                    className="ios-field text-sm font-bold"
                                >
                                    <option value="">Todos los gastos</option>
                                    {actualExpenses.map((expense) => (
                                        <option key={expense.id} value={expense.id}>
                                            {expenseLabel(expense)}
                                        </option>
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
                                total={visibleReceipts.length}
                                filtered={table.filteredRows.length}
                                placeholder="Buscar comprobante"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Archivo</th>
                                        <th className="px-5 py-4">Gasto</th>
                                        <th className="px-5 py-4">Numero</th>
                                        <th className="px-5 py-4">Fecha</th>
                                        <th className="px-5 py-4 text-right">Monto</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {table.paginatedRows.map((receipt) => (
                                        <tr key={receipt.id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-bold">{receipt.storage_file?.original_name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{formatBytes(receipt.storage_file?.size_bytes)}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{receipt.actual_expense?.description ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{receipt.actual_expense?.trip_phase?.project?.code ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">{receipt.receipt_number || '-'}</td>
                                            <td className="px-5 py-4">{formatDateInput(receipt.issued_on) || '-'}</td>
                                            <td className="px-5 py-4 text-right font-black">{formatMoney(receipt.amount)}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton as="a" href={route('receipts.show', receipt.id)} icon="download" label="Descargar comprobante" />
                                                    <IconButton icon="edit" label="Editar comprobante" type="button" onClick={() => edit(receipt)} />
                                                    <IconButton icon="trash" label="Eliminar comprobante" type="button" variant="danger" onClick={() => destroy(receipt)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="6" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay comprobantes para este filtro.
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
    if (!flash.success) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
            {flash.success}
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
                <option value="">Seleccionar</option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.name}
                    </option>
                ))}
            </select>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function TextField({ id, label, value, error, onChange, type = 'text', step }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <TextInput
                id={id}
                type={type}
                step={step}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function expenseLabel(expense) {
    return `${expense.trip_phase?.project?.code ?? 'Sin proyecto'} - ${expense.description} (${formatMoney(expense.real_total)})`;
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}

function formatDateInput(value) {
    return value ? String(value).slice(0, 10) : '';
}

function formatBytes(value) {
    const bytes = Number(value ?? 0);
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
