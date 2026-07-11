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

const statusLabels = {
    draft: 'Borrador',
    scheduled: 'Programado',
    in_progress: 'En progreso',
    completed: 'Completado',
    cancelled: 'Cancelado',
};

export default function TripPhaseIndex({ tripPhases = [], projects = [], teams = [], technicians = [], phaseOptions = [], statusOptions = [] }) {
    const { flash } = usePage().props;
    const [editingPhase, setEditingPhase] = useState(null);
    const [projectFilter, setProjectFilter] = useState('');

    const form = useForm({
        project_id: projects[0]?.id ?? '',
        team_id: teams[0]?.id ?? '',
        assigned_technician_id: '',
        phase: phaseOptions[0] ?? 'Initial Visit',
        starts_on: '',
        ends_on: '',
        volunteer_count: 0,
        staff_count: 0,
        status: 'draft',
    });

    const visiblePhases = useMemo(() => {
        if (!projectFilter) {
            return tripPhases;
        }

        return tripPhases.filter((phase) => String(phase.project_id) === String(projectFilter));
    }, [tripPhases, projectFilter]);
    const searchablePhaseText = useCallback((phase) => [
        phase.project?.code,
        phase.project?.name,
        phase.phase,
        phase.team?.name,
        phase.team?.chapter?.name,
        phase.assigned_technician?.name,
        statusLabels[phase.status] ?? phase.status,
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(visiblePhases, searchablePhaseText, 10);

    const summary = useMemo(() => ({
        phases: visiblePhases.length,
        volunteers: visiblePhases.reduce((total, phase) => total + Number(phase.volunteer_count ?? 0), 0),
        staff: visiblePhases.reduce((total, phase) => total + Number(phase.staff_count ?? 0), 0),
    }), [visiblePhases]);

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        };

        if (editingPhase) {
            form.patch(route('trip-phases.update', editingPhase.id), options);
            return;
        }

        form.post(route('trip-phases.store'), options);
    };

    const edit = (phase) => {
        setEditingPhase(phase);
        form.clearErrors();
        form.setData({
            project_id: phase.project_id,
            team_id: phase.team_id,
            assigned_technician_id: phase.assigned_technician_id ?? '',
            phase: phase.phase,
            starts_on: formatDateInput(phase.starts_on),
            ends_on: formatDateInput(phase.ends_on),
            volunteer_count: phase.volunteer_count ?? 0,
            staff_count: phase.staff_count ?? 0,
            status: phase.status ?? 'draft',
        });
    };

    const resetForm = () => {
        setEditingPhase(null);
        form.clearErrors();
        form.setData({
            project_id: projects[0]?.id ?? '',
            team_id: teams[0]?.id ?? '',
            assigned_technician_id: '',
            phase: phaseOptions[0] ?? 'Initial Visit',
            starts_on: '',
            ends_on: '',
            volunteer_count: 0,
            staff_count: 0,
            status: 'draft',
        });
    };

    const destroy = (phase) => {
        if (!window.confirm(`Eliminar "${phase.phase}" de "${phase.project?.code}"?`)) {
            return;
        }

        router.delete(route('trip-phases.destroy', phase.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingPhase?.id === phase.id) {
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
                        Fases de viaje
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Planificacion de fases
                    </h1>
                </div>
            }
        >
            <Head title="Fases de viaje" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <div className="grid gap-4 md:grid-cols-3">
                    <MetricCard label="Fases" value={summary.phases} />
                    <MetricCard label="Voluntarios" value={summary.volunteers} />
                    <MetricCard label="Staff" value={summary.staff} />
                </div>

                <section className="grid gap-6 xl:grid-cols-[390px_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingPhase ? 'Editar fase' : 'Nueva fase'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Esta informacion alimentara el Draft Budget y los invoices posteriores.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="project_id"
                                label="Proyecto"
                                value={form.data.project_id}
                                options={projects.map((project) => ({ id: project.id, name: `${project.code} - ${project.name}` }))}
                                error={form.errors.project_id}
                                onChange={(value) => form.setData('project_id', value)}
                            />

                            <SelectField
                                id="team_id"
                                label="Equipo"
                                value={form.data.team_id}
                                options={teams.map((team) => ({ id: team.id, name: `${team.name} (${team.chapter?.name ?? 'Sin capitulo'})` }))}
                                error={form.errors.team_id}
                                onChange={(value) => form.setData('team_id', value)}
                            />

                            <SelectField
                                id="assigned_technician_id"
                                label="Tecnico asignado"
                                value={form.data.assigned_technician_id}
                                options={technicians.map((user) => ({ id: user.id, name: `${user.name} (@${user.username})` }))}
                                error={form.errors.assigned_technician_id}
                                emptyLabel="Sin tecnico asignado"
                                onChange={(value) => form.setData('assigned_technician_id', value)}
                            />

                            <SelectField
                                id="phase"
                                label="Fase"
                                value={form.data.phase}
                                options={phaseOptions.map((phase) => ({ id: phase, name: phase }))}
                                error={form.errors.phase}
                                onChange={(value) => form.setData('phase', value)}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    id="starts_on"
                                    label="Inicio"
                                    type="date"
                                    value={form.data.starts_on}
                                    error={form.errors.starts_on}
                                    onChange={(value) => form.setData('starts_on', value)}
                                />
                                <TextField
                                    id="ends_on"
                                    label="Fin"
                                    type="date"
                                    value={form.data.ends_on}
                                    error={form.errors.ends_on}
                                    onChange={(value) => form.setData('ends_on', value)}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    id="volunteer_count"
                                    label="Voluntarios"
                                    type="number"
                                    value={form.data.volunteer_count}
                                    error={form.errors.volunteer_count}
                                    onChange={(value) => form.setData('volunteer_count', value)}
                                />
                                <TextField
                                    id="staff_count"
                                    label="Staff"
                                    type="number"
                                    value={form.data.staff_count}
                                    error={form.errors.staff_count}
                                    onChange={(value) => form.setData('staff_count', value)}
                                />
                            </div>

                            <SelectField
                                id="status"
                                label="Estado"
                                value={form.data.status}
                                options={statusOptions.map((status) => ({ id: status, name: statusLabels[status] ?? status }))}
                                error={form.errors.status}
                                onChange={(value) => form.setData('status', value)}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={form.processing || projects.length === 0 || teams.length === 0}>
                                {editingPhase ? 'Guardar cambios' : 'Crear fase'}
                            </PrimaryButton>
                            {editingPhase && (
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
                                    <h2 className="text-xl font-black">Fases registradas</h2>
                                    <p className="text-sm text-app-muted">{visiblePhases.length} de {tripPhases.length} fases</p>
                                </div>
                                <select
                                    value={projectFilter}
                                    onChange={(event) => setProjectFilter(event.target.value)}
                                    className="ios-field text-sm font-bold"
                                >
                                    <option value="">Todos los proyectos</option>
                                    {projects.map((project) => (
                                        <option key={project.id} value={project.id}>
                                            {project.code} - {project.name}
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
                                total={visiblePhases.length}
                                filtered={table.filteredRows.length}
                                placeholder="Buscar fase"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Proyecto</th>
                                        <th className="px-5 py-4">Fase</th>
                                        <th className="px-5 py-4">Equipo</th>
                                        <th className="px-5 py-4">Fechas</th>
                                        <th className="px-5 py-4">Personas</th>
                                        <th className="px-5 py-4">Estado</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {table.paginatedRows.map((phase) => (
                                        <tr key={phase.id} className="text-sm">
                                            <td className="px-5 py-4">
                                                <div className="font-black">{phase.project?.code}</div>
                                                <div className="text-xs text-app-muted">{phase.project?.name}</div>
                                            </td>
                                            <td className="px-5 py-4 font-bold">{phase.phase}</td>
                                            <td className="px-5 py-4">
                                                <div>{phase.team?.name ?? '-'}</div>
                                                <div className="text-xs text-app-muted">{phase.team?.chapter?.name ?? '-'}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{formatDate(phase.starts_on)}</div>
                                                <div className="text-xs text-app-muted">{formatDate(phase.ends_on)}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div>{phase.volunteer_count} voluntarios</div>
                                                <div className="text-xs text-app-muted">{phase.staff_count} staff</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <StatusPill status={phase.status} />
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar fase" type="button" onClick={() => edit(phase)} />
                                                    <IconButton icon="trash" label="Eliminar fase" type="button" variant="danger" onClick={() => destroy(phase)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="7" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay fases de viaje para este filtro.
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
    const phaseError = flash.errors?.tripPhase?.[0];

    if (!flash.success && !phaseError) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {phaseError && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {phaseError}
                </div>
            )}
        </div>
    );
}

function MetricCard({ label, value }) {
    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <p className="text-sm font-bold text-app-muted">{label}</p>
            <p className="mt-2 text-3xl font-black text-app-text">{value}</p>
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

function StatusPill({ status }) {
    const styles = {
        draft: 'bg-white/35 text-app-muted dark:bg-white/10',
        scheduled: 'bg-brand-primary text-white',
        in_progress: 'bg-amber-400/20 text-amber-700 dark:text-amber-200',
        completed: 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-200',
        cancelled: 'bg-red-500/15 text-red-700 dark:text-red-200',
    };

    return (
        <span className={`rounded-full px-3 py-1 text-xs font-black ${styles[status] ?? styles.draft}`}>
            {statusLabels[status] ?? status}
        </span>
    );
}

function formatDateInput(value) {
    return value ? String(value).slice(0, 10) : '';
}

function formatDate(value) {
    return value ? String(value).slice(0, 10) : '-';
}
