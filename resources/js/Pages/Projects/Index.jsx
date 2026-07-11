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

export default function ProjectIndex({ projects = [], countries = [], communities = [] }) {
    const { flash } = usePage().props;
    const [editingProject, setEditingProject] = useState(null);
    const [countryFilter, setCountryFilter] = useState('');

    const form = useForm({
        country_id: countries[0]?.id ?? '',
        community_id: '',
        code: '',
        name: '',
        started_on: '',
        closed_on: '',
        description: '',
    });

    const availableCommunities = useMemo(() => {
        if (!form.data.country_id) {
            return communities;
        }

        return communities.filter((community) => String(community.country_id) === String(form.data.country_id));
    }, [communities, form.data.country_id]);

    const visibleProjects = useMemo(() => {
        if (!countryFilter) {
            return projects;
        }

        return projects.filter((project) => String(project.country_id) === String(countryFilter));
    }, [projects, countryFilter]);
    const searchableProjectText = useCallback((project) => [
        project.code,
        project.name,
        project.description,
        project.country?.name,
        project.community?.name,
        projectStatus(project),
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(visibleProjects, searchableProjectText, 10);

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        };

        if (editingProject) {
            form.patch(route('projects.update', editingProject.id), options);
            return;
        }

        form.post(route('projects.store'), options);
    };

    const edit = (project) => {
        setEditingProject(project);
        form.clearErrors();
        form.setData({
            country_id: project.country_id,
            community_id: project.community_id ?? '',
            code: project.code ?? '',
            name: project.name ?? '',
            started_on: formatDateInput(project.started_on),
            closed_on: formatDateInput(project.closed_on),
            description: project.description ?? '',
        });
    };

    const resetForm = () => {
        setEditingProject(null);
        form.clearErrors();
        form.setData({
            country_id: countries[0]?.id ?? '',
            community_id: '',
            code: '',
            name: '',
            started_on: '',
            closed_on: '',
            description: '',
        });
    };

    const destroy = (project) => {
        if (!window.confirm(`Eliminar "${project.code} - ${project.name}"?`)) {
            return;
        }

        router.delete(route('projects.destroy', project.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingProject?.id === project.id) {
                    resetForm();
                }
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Proyectos
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Registro base de proyectos
                    </h1>
                </div>
            }
        >
            <Head title="Proyectos" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <section className="grid gap-6 xl:grid-cols-[380px_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingProject ? 'Editar proyecto' : 'Nuevo proyecto'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                El codigo es unico y sera la base para fases de viaje, draft budget e invoices.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="country_id"
                                label="Pais"
                                value={form.data.country_id}
                                options={countries}
                                error={form.errors.country_id}
                                onChange={(value) => {
                                    form.setData('country_id', value);
                                    if (value && !communities.some((community) => String(community.id) === String(form.data.community_id) && String(community.country_id) === String(value))) {
                                        form.setData('community_id', '');
                                    }
                                }}
                            />

                            <SelectField
                                id="community_id"
                                label="Comunidad"
                                value={form.data.community_id}
                                options={availableCommunities}
                                error={form.errors.community_id}
                                emptyLabel="Sin comunidad"
                                onChange={(value) => form.setData('community_id', value)}
                            />

                            <TextField
                                id="code"
                                label="Codigo"
                                value={form.data.code}
                                error={form.errors.code}
                                onChange={(value) => form.setData('code', value.toUpperCase())}
                            />

                            <TextField
                                id="name"
                                label="Nombre del proyecto"
                                value={form.data.name}
                                error={form.errors.name}
                                onChange={(value) => form.setData('name', value)}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    id="started_on"
                                    label="Fecha inicio"
                                    type="date"
                                    value={form.data.started_on}
                                    error={form.errors.started_on}
                                    onChange={(value) => form.setData('started_on', value)}
                                />
                                <TextField
                                    id="closed_on"
                                    label="Fecha cierre"
                                    type="date"
                                    value={form.data.closed_on}
                                    error={form.errors.closed_on}
                                    onChange={(value) => form.setData('closed_on', value)}
                                />
                            </div>

                            <TextareaField
                                id="description"
                                label="Descripcion"
                                value={form.data.description}
                                error={form.errors.description}
                                onChange={(value) => form.setData('description', value)}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={form.processing || countries.length === 0}>
                                {editingProject ? 'Guardar cambios' : 'Crear proyecto'}
                            </PrimaryButton>
                            {editingProject && (
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
                                    <h2 className="text-xl font-black">Proyectos registrados</h2>
                                    <p className="text-sm text-app-muted">{visibleProjects.length} de {projects.length} proyectos</p>
                                </div>
                                <select
                                    value={countryFilter}
                                    onChange={(event) => setCountryFilter(event.target.value)}
                                    className="ios-field text-sm font-bold"
                                >
                                    <option value="">Todos los paises</option>
                                    {countries.map((country) => (
                                        <option key={country.id} value={country.id}>
                                            {country.name}
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
                                total={visibleProjects.length}
                                filtered={table.filteredRows.length}
                                placeholder="Buscar proyecto"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Codigo</th>
                                        <th className="px-5 py-4">Proyecto</th>
                                        <th className="px-5 py-4">Ubicacion</th>
                                        <th className="px-5 py-4">Estado</th>
                                        <th className="px-5 py-4">Fases</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {table.paginatedRows.map((project) => (
                                        <tr key={project.id} className="text-sm">
                                            <td className="px-5 py-4 font-black">{project.code}</td>
                                            <td className="px-5 py-4">
                                                <div className="font-bold">{project.name}</div>
                                                <div className="text-xs text-app-muted">{project.description || '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{project.country?.name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{project.community?.name ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <StatusPill status={projectStatus(project)} />
                                            </td>
                                            <td className="px-5 py-4">{project.trip_phases_count}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar proyecto" type="button" onClick={() => edit(project)} />
                                                    <IconButton icon="trash" label="Eliminar proyecto" type="button" variant="danger" onClick={() => destroy(project)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="6" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay proyectos para este filtro.
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
    const projectError = flash.errors?.project?.[0];

    if (!flash.success && !projectError) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {projectError && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {projectError}
                </div>
            )}
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

function TextField({ id, label, value, error, onChange, type = 'text' }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <TextInput
                id={id}
                type={type}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function TextareaField({ id, label, value, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <textarea
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block min-h-24 w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm placeholder:text-app-muted/70 focus:border-brand-primary focus:ring-brand-primary dark:bg-white/10"
            />
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function StatusPill({ status }) {
    const styles = {
        Planned: 'bg-white/35 text-app-muted dark:bg-white/10',
        Active: 'bg-brand-primary text-white',
        Closed: 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-200',
    };

    return (
        <span className={`rounded-full px-3 py-1 text-xs font-black ${styles[status]}`}>
            {status}
        </span>
    );
}

function projectStatus(project) {
    const today = new Date();
    const startedOn = project.started_on ? new Date(project.started_on) : null;
    const closedOn = project.closed_on ? new Date(project.closed_on) : null;

    if (closedOn && closedOn < today) {
        return 'Closed';
    }

    if (!startedOn || startedOn <= today) {
        return 'Active';
    }

    return 'Planned';
}

function formatDateInput(value) {
    return value ? String(value).slice(0, 10) : '';
}
