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

export default function ActualExpenseIndex({ actualExpenses = [], tripPhases = [], estimatedExpenses = [], expenseCategories = [] }) {
    const { flash } = usePage().props;
    const [editingExpense, setEditingExpense] = useState(null);
    const [phaseFilter, setPhaseFilter] = useState(tripPhases[0]?.id ?? '');

    const form = useForm({
        trip_phase_id: phaseFilter,
        estimated_expense_id: '',
        expense_category_id: expenseCategories[0]?.id ?? '',
        description: '',
        unit: '',
        final_unit_cost: 0,
        final_quantity: 1,
        receipt_number: '',
    });

    const availableEstimated = useMemo(() => {
        if (!form.data.trip_phase_id) {
            return estimatedExpenses;
        }

        return estimatedExpenses.filter((expense) => String(expense.trip_phase_id) === String(form.data.trip_phase_id));
    }, [estimatedExpenses, form.data.trip_phase_id]);

    const visibleExpenses = useMemo(() => {
        if (!phaseFilter) {
            return actualExpenses;
        }

        return actualExpenses.filter((expense) => String(expense.trip_phase_id) === String(phaseFilter));
    }, [actualExpenses, phaseFilter]);
    const searchableExpenseText = useCallback((expense) => [
        expense.description,
        expense.unit,
        expense.receipt_number,
        expense.fund_type,
        expense.expense_category?.name,
        expense.trip_phase?.phase,
        expense.trip_phase?.project?.code,
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(visibleExpenses, searchableExpenseText, 10);

    const visibleEstimated = useMemo(() => {
        if (!phaseFilter) {
            return estimatedExpenses;
        }

        return estimatedExpenses.filter((expense) => String(expense.trip_phase_id) === String(phaseFilter));
    }, [estimatedExpenses, phaseFilter]);

    const summary = useMemo(() => summarize(visibleExpenses, visibleEstimated), [visibleExpenses, visibleEstimated]);
    const lineTotal = Number(form.data.final_unit_cost || 0) * Number(form.data.final_quantity || 0);

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        };

        if (editingExpense) {
            form.patch(route('actual-expenses.update', editingExpense.id), options);
            return;
        }

        form.post(route('actual-expenses.store'), options);
    };

    const edit = (expense) => {
        setEditingExpense(expense);
        setPhaseFilter(expense.trip_phase_id);
        form.clearErrors();
        form.setData({
            trip_phase_id: expense.trip_phase_id,
            estimated_expense_id: expense.estimated_expense_id ?? '',
            expense_category_id: expense.expense_category_id,
            description: expense.description ?? '',
            unit: expense.unit ?? '',
            final_unit_cost: expense.final_unit_cost ?? 0,
            final_quantity: expense.final_quantity ?? 1,
            receipt_number: expense.receipt_number ?? '',
        });
    };

    const resetForm = () => {
        setEditingExpense(null);
        form.clearErrors();
        form.setData({
            trip_phase_id: phaseFilter,
            estimated_expense_id: '',
            expense_category_id: expenseCategories[0]?.id ?? '',
            description: '',
            unit: '',
            final_unit_cost: 0,
            final_quantity: 1,
            receipt_number: '',
        });
    };

    const destroy = (expense) => {
        if (!window.confirm(`Eliminar "${expense.description}"?`)) {
            return;
        }

        router.delete(route('actual-expenses.destroy', expense.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingExpense?.id === expense.id) {
                    resetForm();
                }
            },
        });
    };

    const changeEstimatedExpense = (value) => {
        form.setData('estimated_expense_id', value);
        const estimated = estimatedExpenses.find((expense) => String(expense.id) === String(value));

        if (!estimated) {
            return;
        }

        form.setData({
            ...form.data,
            estimated_expense_id: value,
            expense_category_id: estimated.expense_category_id,
            description: estimated.description ?? '',
            unit: estimated.unit ?? '',
            final_unit_cost: estimated.initial_unit_cost ?? 0,
            final_quantity: estimated.initial_quantity ?? 1,
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
                        Gastos reales
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Ejecucion y conciliacion
                    </h1>
                </div>
            }
        >
            <Head title="Gastos reales" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <div className="grid gap-4 md:grid-cols-4">
                    <MetricCard label="Estimado" value={formatMoney(summary.estimated)} />
                    <MetricCard label="Real" value={formatMoney(summary.real)} />
                    <MetricCard label="Variacion" value={formatMoney(summary.variance)} tone={summary.variance > 0 ? 'bad' : 'ok'} />
                    <MetricCard label="Lineas reales" value={summary.count} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[410px_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingExpense ? 'Editar gasto real' : 'Nuevo gasto real'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Puede venir de una linea estimada o registrarse como gasto no presupuestado.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="trip_phase_id"
                                label="Fase de viaje"
                                value={form.data.trip_phase_id}
                                options={tripPhases.map((phase) => ({ id: phase.id, name: phaseLabel(phase) }))}
                                error={form.errors.trip_phase_id}
                                onChange={(value) => {
                                    form.setData('trip_phase_id', value);
                                    if (!estimatedExpenses.some((expense) => String(expense.id) === String(form.data.estimated_expense_id) && String(expense.trip_phase_id) === String(value))) {
                                        form.setData('estimated_expense_id', '');
                                    }
                                }}
                            />

                            <SelectField
                                id="estimated_expense_id"
                                label="Linea estimada opcional"
                                value={form.data.estimated_expense_id}
                                options={availableEstimated.map((expense) => ({ id: expense.id, name: `${expense.description} - ${formatMoney(expense.estimated_total)}` }))}
                                error={form.errors.estimated_expense_id}
                                emptyLabel="Sin linea estimada"
                                onChange={changeEstimatedExpense}
                            />

                            <SelectField
                                id="expense_category_id"
                                label="Categoria"
                                value={form.data.expense_category_id}
                                options={expenseCategories.map((category) => ({ id: category.id, name: `${category.name} (${category.fund_type})` }))}
                                error={form.errors.expense_category_id}
                                onChange={(value) => form.setData('expense_category_id', value)}
                            />

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
                                    id="final_unit_cost"
                                    label="Costo final"
                                    type="number"
                                    step="0.01"
                                    value={form.data.final_unit_cost}
                                    error={form.errors.final_unit_cost}
                                    onChange={(value) => form.setData('final_unit_cost', value)}
                                />
                                <TextField
                                    id="final_quantity"
                                    label="Cantidad final"
                                    type="number"
                                    step="0.01"
                                    value={form.data.final_quantity}
                                    error={form.errors.final_quantity}
                                    onChange={(value) => form.setData('final_quantity', value)}
                                />
                            </div>

                            <TextField
                                id="receipt_number"
                                label="Numero de recibo/factura"
                                value={form.data.receipt_number}
                                error={form.errors.receipt_number}
                                onChange={(value) => form.setData('receipt_number', value)}
                            />

                            <div className="rounded-2xl bg-white/30 px-4 py-3 text-sm font-black text-app-text dark:bg-white/10">
                                Total calculado: {formatMoney(lineTotal)}
                            </div>
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={form.processing || tripPhases.length === 0 || expenseCategories.length === 0}>
                                {editingExpense ? 'Guardar cambios' : 'Crear gasto'}
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
                                    <h2 className="text-xl font-black">Gastos registrados</h2>
                                    <p className="text-sm text-app-muted">{visibleExpenses.length} de {actualExpenses.length} lineas</p>
                                </div>
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
                                placeholder="Buscar gasto"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Descripcion</th>
                                        <th className="px-5 py-4">Categoria</th>
                                        <th className="px-5 py-4">Fase</th>
                                        <th className="px-5 py-4 text-right">Real</th>
                                        <th className="px-5 py-4 text-right">Estimado</th>
                                        <th className="px-5 py-4">Recibo</th>
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
                                            <td className="px-5 py-4 text-right font-black">{formatMoney(expense.real_total)}</td>
                                            <td className="px-5 py-4 text-right">{formatMoney(expense.estimated_expense?.estimated_total ?? 0)}</td>
                                            <td className="px-5 py-4">
                                                <div>{expense.receipt_number || '-'}</div>
                                                <div className="text-xs text-app-muted">{expense.receipts_count} archivos</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar gasto" type="button" onClick={() => edit(expense)} />
                                                    <IconButton icon="trash" label="Eliminar gasto" type="button" variant="danger" onClick={() => destroy(expense)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="7" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay gastos reales para este filtro.
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
    const error = flash.errors?.actualExpense?.[0];

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

function MetricCard({ label, value, tone = 'neutral' }) {
    const toneClass = tone === 'bad' ? 'text-red-600 dark:text-red-300' : tone === 'ok' ? 'text-emerald-700 dark:text-emerald-200' : 'text-app-text';

    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className={`mt-2 text-2xl font-black ${toneClass}`}>{value}</p>
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

function summarize(actualExpenses, estimatedExpenses) {
    const real = actualExpenses.reduce((total, expense) => total + Number(expense.real_total ?? 0), 0);
    const estimated = estimatedExpenses.reduce((total, expense) => total + Number(expense.estimated_total ?? 0), 0);

    return {
        estimated,
        real,
        variance: real - estimated,
        count: actualExpenses.length,
    };
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
