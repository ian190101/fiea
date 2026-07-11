import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

export default function EstimatedExpenseIndex({ estimatedExpenses = [], tripPhases = [], expenseCategories = [] }) {
    const { flash } = usePage().props;
    const [editingExpense, setEditingExpense] = useState(null);
    const [phaseFilter, setPhaseFilter] = useState(tripPhases[0]?.id ?? '');

    const form = useForm({
        trip_phase_id: phaseFilter,
        expense_category_id: expenseCategories[0]?.id ?? '',
        description: '',
        unit: '',
        initial_unit_cost: 0,
        initial_quantity: 1,
    });

    const selectedCategory = useMemo(
        () => expenseCategories.find((category) => String(category.id) === String(form.data.expense_category_id)) ?? null,
        [expenseCategories, form.data.expense_category_id],
    );
    const selectedPhase = useMemo(
        () => tripPhases.find((phase) => String(phase.id) === String(phaseFilter)) ?? null,
        [tripPhases, phaseFilter],
    );

    const visibleExpenses = useMemo(() => {
        if (!phaseFilter) {
            return estimatedExpenses;
        }

        return estimatedExpenses.filter((expense) => String(expense.trip_phase_id) === String(phaseFilter));
    }, [estimatedExpenses, phaseFilter]);
    const searchableExpenseText = useCallback((expense) => [
        expense.description,
        expense.unit,
        expense.fund_type,
        expense.expense_category?.name,
        expense.trip_phase?.phase,
        expense.trip_phase?.project?.code,
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(visibleExpenses, searchableExpenseText, 10);

    const summary = useMemo(() => summarizeExpenses(visibleExpenses), [visibleExpenses]);
    const lineTotal = Number(form.data.initial_unit_cost || 0) * Number(form.data.initial_quantity || 0);

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        };

        if (editingExpense) {
            form.patch(route('estimated-expenses.update', editingExpense.id), options);
            return;
        }

        form.post(route('estimated-expenses.store'), options);
    };

    const edit = (expense) => {
        setEditingExpense(expense);
        setPhaseFilter(expense.trip_phase_id);
        form.clearErrors();
        form.setData({
            trip_phase_id: expense.trip_phase_id,
            expense_category_id: expense.expense_category_id,
            description: expense.description ?? '',
            unit: expense.unit ?? '',
            initial_unit_cost: expense.initial_unit_cost ?? 0,
            initial_quantity: expense.initial_quantity ?? 1,
        });
    };

    const resetForm = () => {
        setEditingExpense(null);
        form.clearErrors();
        form.setData({
            trip_phase_id: phaseFilter,
            expense_category_id: expenseCategories[0]?.id ?? '',
            description: '',
            unit: '',
            initial_unit_cost: 0,
            initial_quantity: 1,
        });
    };

    const destroy = (expense) => {
        if (!window.confirm(`Eliminar "${expense.description}"?`)) {
            return;
        }

        router.delete(route('estimated-expenses.destroy', expense.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingExpense?.id === expense.id) {
                    resetForm();
                }
            },
        });
    };

    const changePhaseFilter = (value) => {
        setPhaseFilter(value);
        if (!editingExpense) {
            form.setData('trip_phase_id', value);
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Draft Budget
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Gastos estimados
                    </h1>
                </div>
            }
        >
            <Head title="Draft Budget" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <div className="grid gap-4 md:grid-cols-4">
                    <MetricCard label="DR total" value={formatMoney(summary.dr)} />
                    <MetricCard label="WODR total" value={formatMoney(summary.wodr)} />
                    <MetricCard label="Grand total" value={formatMoney(summary.total)} />
                    <MetricCard label="Lineas" value={summary.count} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[400px_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingExpense ? 'Editar linea' : 'Nueva linea'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Los textos de fase y categorias quedan preparados para el PDF en ingles.
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

                            <SelectField
                                id="expense_category_id"
                                label="Categoria"
                                value={form.data.expense_category_id}
                                options={expenseCategories.map((category) => ({ id: category.id, name: `${category.name} (${category.fund_type})` }))}
                                error={form.errors.expense_category_id}
                                onChange={(value) => form.setData('expense_category_id', value)}
                            />

                            {selectedCategory && (
                                <div className="rounded-2xl border border-white/35 bg-white/25 px-4 py-3 text-sm font-bold text-app-text dark:border-white/10 dark:bg-white/10">
                                    <div>Fund: {selectedCategory.fund_type}</div>
                                    <div className="mt-1 text-xs text-app-muted">
                                        Fee: {selectedCategory.applies_service_fee ? `${selectedCategory.service_fee_percentage}%` : 'No'} · Contingency: {selectedCategory.applies_contingency ? 'Yes' : 'No'}
                                    </div>
                                </div>
                            )}

                            <TextField
                                id="description"
                                label="Descripcion"
                                value={form.data.description}
                                error={form.errors.description}
                                onChange={(value) => form.setData('description', value)}
                            />

                            <TextField
                                id="unit"
                                label="Unidad"
                                value={form.data.unit}
                                error={form.errors.unit}
                                onChange={(value) => form.setData('unit', value)}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    id="initial_unit_cost"
                                    label="Costo unitario"
                                    type="number"
                                    step="0.01"
                                    value={form.data.initial_unit_cost}
                                    error={form.errors.initial_unit_cost}
                                    onChange={(value) => form.setData('initial_unit_cost', value)}
                                />
                                <TextField
                                    id="initial_quantity"
                                    label="Cantidad"
                                    type="number"
                                    step="0.01"
                                    value={form.data.initial_quantity}
                                    error={form.errors.initial_quantity}
                                    onChange={(value) => form.setData('initial_quantity', value)}
                                />
                            </div>

                            <div className="rounded-2xl bg-white/30 px-4 py-3 text-sm font-black text-app-text dark:bg-white/10">
                                Total calculado: {formatMoney(lineTotal)}
                            </div>
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={form.processing || tripPhases.length === 0 || expenseCategories.length === 0}>
                                {editingExpense ? 'Guardar cambios' : 'Crear linea'}
                            </PrimaryButton>
                            {editingExpense && (
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
                                    <h2 className="text-xl font-black">Lineas del Draft Budget</h2>
                                    <p className="text-sm text-app-muted">{visibleExpenses.length} de {estimatedExpenses.length} lineas</p>
                                </div>
                                <div className="flex flex-col gap-2 md:items-end">
                                    <select
                                        value={phaseFilter}
                                        onChange={(event) => changePhaseFilter(event.target.value)}
                                        className="ios-field text-sm font-bold"
                                    >
                                        <option value="">Todas las fases</option>
                                        {tripPhases.map((phase) => (
                                            <option key={phase.id} value={phase.id}>
                                                {phaseLabel(phase)}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedPhase && (
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <IconButton
                                                as={Link}
                                                method="post"
                                                href={route('draft-budget-pdf.store', selectedPhase.id)}
                                                icon="file"
                                                label="Generar PDF"
                                                preserveScroll
                                            />
                                            {selectedPhase.draft_pdf_file_id && (
                                                <IconButton
                                                    as="a"
                                                    href={route('draft-budget-pdf.show', selectedPhase.id)}
                                                    icon="download"
                                                    label="Descargar PDF"
                                                />
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <TableControls
                                query={table.query}
                                onQueryChange={table.updateQuery}
                                page={table.page}
                                totalPages={table.totalPages}
                                pageSize={table.pageSize}
                                onPageSizeChange={table.updatePageSize}
                                onPageChange={table.setPage}
                                total={visibleExpenses.length}
                                filtered={table.filteredRows.length}
                                placeholder="Buscar linea"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Descripcion</th>
                                        <th className="px-5 py-4">Categoria</th>
                                        <th className="px-5 py-4">Fase</th>
                                        <th className="px-5 py-4 text-right">Costo</th>
                                        <th className="px-5 py-4 text-right">Cantidad</th>
                                        <th className="px-5 py-4 text-right">Total</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {table.paginatedRows.map((expense) => (
                                        <tr key={expense.id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-bold">{expense.description}</div>
                                                <div className="text-xs text-app-muted">{expense.unit || '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{expense.expense_category?.name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{expense.fund_type}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{expense.trip_phase?.phase ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{expense.trip_phase?.project?.code ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4 text-right">{formatMoney(expense.initial_unit_cost)}</td>
                                            <td className="px-5 py-4 text-right">{formatNumber(expense.initial_quantity)}</td>
                                            <td className="px-5 py-4 text-right font-black">{formatMoney(expense.estimated_total)}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar linea" type="button" onClick={() => edit(expense)} />
                                                    <IconButton icon="trash" label="Eliminar linea" type="button" variant="danger" onClick={() => destroy(expense)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="7" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay lineas de presupuesto para este filtro.
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
    const expenseError = flash.errors?.estimatedExpense?.[0];

    if (!flash.success && !expenseError) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {expenseError && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {expenseError}
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

function summarizeExpenses(expenses) {
    return expenses.reduce((summary, expense) => {
        const total = Number(expense.estimated_total ?? 0);

        if (expense.fund_type === 'DR') {
            summary.dr += total;
        } else {
            summary.wodr += total;
        }

        summary.total += total;
        summary.count += 1;

        return summary;
    }, { dr: 0, wodr: 0, total: 0, count: 0 });
}

function phaseLabel(phase) {
    return `${phase.project?.code ?? 'Sin proyecto'} - ${phase.phase} (${phase.team?.name ?? 'Sin equipo'})`;
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}

function formatNumber(value) {
    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits: 2,
    }).format(Number(value ?? 0));
}
