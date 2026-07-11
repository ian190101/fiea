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

const catalogDefinitions = [
    {
        key: 'chapter-types',
        prop: 'chapterTypes',
        title: 'Tipos de capitulo',
        description: 'Clasifica capitulos universitarios, profesionales u otros tipos futuros.',
        fields: [
            { name: 'name', label: 'Nombre', type: 'text' },
            { name: 'description', label: 'Descripcion', type: 'textarea' },
        ],
        columns: [
            { key: 'name', label: 'Nombre' },
            { key: 'description', label: 'Descripcion' },
        ],
    },
    {
        key: 'countries',
        prop: 'countries',
        title: 'Paises',
        description: 'Base geografica para proyectos y comunidades.',
        fields: [
            { name: 'name', label: 'Nombre', type: 'text' },
            { name: 'description', label: 'Descripcion', type: 'textarea' },
        ],
        columns: [
            { key: 'name', label: 'Nombre' },
            { key: 'description', label: 'Descripcion' },
        ],
    },
    {
        key: 'communities',
        prop: 'communities',
        title: 'Comunidades',
        description: 'Comunidades vinculadas a un pais para agrupar proyectos.',
        fields: [
            { name: 'country_id', label: 'Pais', type: 'select', source: 'countries' },
            { name: 'name', label: 'Nombre', type: 'text' },
            { name: 'description', label: 'Descripcion', type: 'textarea' },
        ],
        columns: [
            { key: 'name', label: 'Nombre' },
            { key: 'country.name', label: 'Pais' },
            { key: 'description', label: 'Descripcion' },
        ],
    },
    {
        key: 'universities',
        prop: 'universities',
        title: 'Universidades',
        description: 'Registro opcional y basico para capitulos que lo requieran.',
        fields: [{ name: 'name', label: 'Nombre', type: 'text' }],
        columns: [{ key: 'name', label: 'Nombre' }],
    },
    {
        key: 'expense-categories',
        prop: 'expenseCategories',
        title: 'Categorias de gasto',
        description: 'Define clasificacion contable DR/WODR, fees y contingencia.',
        fields: [
            { name: 'name', label: 'Nombre', type: 'text' },
            { name: 'description', label: 'Descripcion', type: 'textarea' },
            { name: 'fund_type', label: 'Fondo', type: 'select', options: [{ id: 'DR', name: 'DR' }, { id: 'WODR', name: 'WODR' }] },
            { name: 'service_fee_percentage', label: 'Fee %', type: 'number' },
            { name: 'applies_service_fee', label: 'Aplica fee', type: 'checkbox' },
            { name: 'applies_contingency', label: 'Aplica contingencia', type: 'checkbox' },
        ],
        columns: [
            { key: 'name', label: 'Nombre' },
            { key: 'fund_type', label: 'Fondo' },
            { key: 'service_fee_percentage', label: 'Fee %' },
            { key: 'applies_service_fee', label: 'Fee' },
            { key: 'applies_contingency', label: 'Contingencia' },
        ],
    },
];

export default function CatalogIndex({ catalogs = {} }) {
    const { flash } = usePage().props;
    const [activeKey, setActiveKey] = useState(catalogDefinitions[0].key);
    const [editingItem, setEditingItem] = useState(null);
    const activeCatalog = catalogDefinitions.find((catalog) => catalog.key === activeKey);
    const rows = catalogs[activeCatalog.prop] ?? [];
    const formDefaults = useMemo(() => buildDefaults(activeCatalog, catalogs), [activeCatalog, catalogs]);
    const searchableRowText = useCallback((row) => activeCatalog.columns
        .map((column) => formatCell(valueAt(row, column.key)))
        .join(' '), [activeCatalog]);
    const table = useLocalTable(rows, searchableRowText, 10);

    const { data, setData, post, patch, processing, errors, reset, clearErrors } = useForm(formDefaults);

    const changeCatalog = (key) => {
        const catalog = catalogDefinitions.find((item) => item.key === key);
        setActiveKey(key);
        setEditingItem(null);
        clearErrors();
        reset();
        Object.entries(buildDefaults(catalog, catalogs)).forEach(([field, value]) => setData(field, value));
    };

    const edit = (item) => {
        setEditingItem(item);
        clearErrors();
        activeCatalog.fields.forEach((field) => {
            setData(field.name, item[field.name] ?? defaultValueFor(field, catalogs));
        });
    };

    const cancelEdit = () => {
        setEditingItem(null);
        clearErrors();
        Object.entries(formDefaults).forEach(([field, value]) => setData(field, value));
    };

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
        };

        if (editingItem) {
            patch(route('catalogs.update', [activeCatalog.key, editingItem.id]), options);
            return;
        }

        post(route('catalogs.store', activeCatalog.key), options);
    };

    const destroy = (item) => {
        if (!window.confirm(`Eliminar "${item.name}"?`)) {
            return;
        }

        router.delete(route('catalogs.destroy', [activeCatalog.key, item.id]), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingItem?.id === item.id) {
                    cancelEdit();
                }
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Configuracion base
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Catalogos del sistema
                    </h1>
                </div>
            }
        >
            <Head title="Catalogos" />

            <div className="grid max-w-7xl gap-6 xl:grid-cols-[290px_1fr]">
                <aside className="glass-panel rounded-[2rem] p-3">
                    <div className="space-y-2">
                        {catalogDefinitions.map((catalog) => (
                            <button
                                key={catalog.key}
                                type="button"
                                onClick={() => changeCatalog(catalog.key)}
                                className={`w-full rounded-2xl px-4 py-3 text-left transition ${
                                    activeKey === catalog.key
                                        ? 'bg-brand-primary text-white shadow-lg shadow-stone-950/10'
                                        : 'text-app-muted hover:bg-white/35 hover:text-app-text dark:hover:bg-white/10'
                                }`}
                            >
                                <span className="block text-sm font-black">{catalog.title}</span>
                                <span className="mt-1 block text-xs opacity-80">{catalog.description}</span>
                            </button>
                        ))}
                    </div>
                </aside>

                <section className="grid gap-6 lg:grid-cols-[minmax(280px,360px)_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black text-app-text">
                                {editingItem ? 'Editar registro' : 'Nuevo registro'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                {activeCatalog.description}
                            </p>
                        </div>

                        {flash.success && (
                            <div className="mt-5 rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                                {flash.success}
                            </div>
                        )}

                        {flash.errors?.catalog && (
                            <div className="mt-5 rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                                {flash.errors.catalog[0]}
                            </div>
                        )}

                        <div className="mt-6 space-y-4">
                            {activeCatalog.fields.map((field) => (
                                <Field
                                    key={field.name}
                                    field={field}
                                    value={data[field.name]}
                                    catalogs={catalogs}
                                    error={errors[field.name]}
                                    onChange={(value) => setData(field.name, value)}
                                />
                            ))}
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={processing}>
                                {editingItem ? 'Guardar cambios' : 'Crear'}
                            </PrimaryButton>
                            {editingItem && (
                                <button type="button" onClick={cancelEdit} className="glass-button">
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <div>
                                <h2 className="text-xl font-black">{activeCatalog.title}</h2>
                                <p className="text-sm text-app-muted">{table.filteredRows.length} de {rows.length} registros</p>
                            </div>
                            <TableControls
                                query={table.query}
                                onQueryChange={table.updateQuery}
                                page={table.page}
                                totalPages={table.totalPages}
                                pageSize={table.pageSize}
                                onPageSizeChange={table.updatePageSize}
                                onPageChange={table.setPage}
                                total={rows.length}
                                filtered={table.filteredRows.length}
                                placeholder="Buscar registro"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        {activeCatalog.columns.map((column) => (
                                            <th key={column.key} className="px-5 py-4">{column.label}</th>
                                        ))}
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {table.paginatedRows.map((row) => (
                                        <tr key={row.id} className="text-sm">
                                            {activeCatalog.columns.map((column) => (
                                                <td key={column.key} className="px-5 py-4 text-app-text">
                                                    {formatCell(valueAt(row, column.key))}
                                                </td>
                                            ))}
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar registro" type="button" onClick={() => edit(row)} />
                                                    <IconButton icon="trash" label="Eliminar registro" type="button" variant="danger" onClick={() => destroy(row)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td className="px-5 py-10 text-center text-sm font-semibold text-app-muted" colSpan={activeCatalog.columns.length + 1}>
                                                Aun no hay registros en este catalogo.
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

function Field({ field, value, catalogs, error, onChange }) {
    if (field.type === 'textarea') {
        return (
            <div>
                <InputLabel htmlFor={field.name} value={field.label} />
                <textarea
                    id={field.name}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                    className="mt-1 block min-h-24 w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm placeholder:text-app-muted/70 focus:border-brand-primary focus:ring-brand-primary dark:bg-white/10"
                />
                <InputError message={error} className="mt-2" />
            </div>
        );
    }

    if (field.type === 'select') {
        const options = field.options ?? catalogs[field.source] ?? [];

        return (
            <div>
                <InputLabel htmlFor={field.name} value={field.label} />
                <select
                    id={field.name}
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

    if (field.type === 'checkbox') {
        return (
            <label className="flex items-center justify-between rounded-2xl border border-white/35 bg-white/25 px-4 py-3 text-sm font-bold text-app-text dark:border-white/10 dark:bg-white/10">
                <span>{field.label}</span>
                <input
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(event) => onChange(event.target.checked)}
                    className="rounded border-app-border text-brand-primary focus:ring-brand-primary"
                />
            </label>
        );
    }

    return (
        <div>
            <InputLabel htmlFor={field.name} value={field.label} />
            <TextInput
                id={field.name}
                type={field.type}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function buildDefaults(catalog, catalogs) {
    return Object.fromEntries(
        catalog.fields.map((field) => [field.name, defaultValueFor(field, catalogs)]),
    );
}

function defaultValueFor(field, catalogs) {
    if (field.type === 'checkbox') {
        return false;
    }

    if (field.type === 'number') {
        return 0;
    }

    if (field.name === 'fund_type') {
        return 'DR';
    }

    if (field.type === 'select') {
        const options = field.options ?? catalogs[field.source] ?? [];
        return options[0]?.id ?? '';
    }

    return '';
}

function valueAt(row, key) {
    return key.split('.').reduce((value, segment) => value?.[segment], row);
}

function formatCell(value) {
    if (typeof value === 'boolean') {
        return value ? 'Si' : 'No';
    }

    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return value;
}
