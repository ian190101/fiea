import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

export default function ChapterIndex({ chapters = [], teams = [], chapterTypes = [], universities = [] }) {
    const { flash } = usePage().props;
    const [editingChapter, setEditingChapter] = useState(null);
    const [editingTeam, setEditingTeam] = useState(null);
    const [selectedChapterId, setSelectedChapterId] = useState(chapters[0]?.id ?? '');

    const selectedChapter = useMemo(
        () => chapters.find((chapter) => String(chapter.id) === String(selectedChapterId)) ?? null,
        [chapters, selectedChapterId],
    );

    const filteredTeams = useMemo(
        () => teams.filter((team) => String(team.chapter_id) === String(selectedChapterId)),
        [teams, selectedChapterId],
    );
    const chapterSearchText = useCallback((chapter) => [
        chapter.name,
        chapter.chapter_type?.name,
        chapter.university?.name,
        chapter.description,
    ].filter(Boolean).join(' '), []);
    const teamSearchText = useCallback((team) => [
        team.name,
        team.description,
        team.credit_balance,
    ].filter(Boolean).join(' '), []);
    const chapterTable = useLocalTable(chapters, chapterSearchText, 10);
    const teamTable = useLocalTable(filteredTeams, teamSearchText, 10);

    const chapterForm = useForm({
        chapter_type_id: chapterTypes[0]?.id ?? '',
        university_id: '',
        name: '',
        description: '',
    });

    const teamForm = useForm({
        chapter_id: selectedChapterId,
        name: '',
        description: '',
        credit_balance: 0,
    });

    useEffect(() => {
        if (chapters.length === 0) {
            setSelectedChapterId('');
            return;
        }

        if (!chapters.some((chapter) => String(chapter.id) === String(selectedChapterId))) {
            setSelectedChapterId(chapters[0].id);
        }
    }, [chapters, selectedChapterId]);

    useEffect(() => {
        if (!editingTeam) {
            teamForm.setData('chapter_id', selectedChapterId);
        }
    }, [selectedChapterId]);

    const submitChapter = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetChapterForm(),
        };

        if (editingChapter) {
            chapterForm.patch(route('chapters.update', editingChapter.id), options);
            return;
        }

        chapterForm.post(route('chapters.store'), options);
    };

    const submitTeam = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => resetTeamForm(),
        };

        if (editingTeam) {
            teamForm.patch(route('teams.update', editingTeam.id), options);
            return;
        }

        teamForm.post(route('teams.store'), options);
    };

    const editChapter = (chapter) => {
        setEditingChapter(chapter);
        chapterForm.clearErrors();
        chapterForm.setData({
            chapter_type_id: chapter.chapter_type_id,
            university_id: chapter.university_id ?? '',
            name: chapter.name ?? '',
            description: chapter.description ?? '',
        });
    };

    const editTeam = (team) => {
        setEditingTeam(team);
        setSelectedChapterId(team.chapter_id);
        teamForm.clearErrors();
        teamForm.setData({
            chapter_id: team.chapter_id,
            name: team.name ?? '',
            description: team.description ?? '',
            credit_balance: team.credit_balance ?? 0,
        });
    };

    const resetChapterForm = () => {
        setEditingChapter(null);
        chapterForm.clearErrors();
        chapterForm.setData({
            chapter_type_id: chapterTypes[0]?.id ?? '',
            university_id: '',
            name: '',
            description: '',
        });
    };

    const resetTeamForm = () => {
        setEditingTeam(null);
        teamForm.clearErrors();
        teamForm.setData({
            chapter_id: selectedChapterId,
            name: '',
            description: '',
            credit_balance: 0,
        });
    };

    const deleteChapter = (chapter) => {
        if (!window.confirm(`Eliminar "${chapter.name}"?`)) {
            return;
        }

        router.delete(route('chapters.destroy', chapter.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingChapter?.id === chapter.id) {
                    resetChapterForm();
                }
            },
        });
    };

    const deleteTeam = (team) => {
        if (!window.confirm(`Eliminar "${team.name}"?`)) {
            return;
        }

        router.delete(route('teams.destroy', team.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingTeam?.id === team.id) {
                    resetTeamForm();
                }
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">
                        Capítulos y equipos
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">
                        Administracion de equipos FIEA
                    </h1>
                </div>
            }
        >
            <Head title="Capitulos y equipos" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <section className="grid gap-6 xl:grid-cols-[360px_1fr]">
                    <form onSubmit={submitChapter} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingChapter ? 'Editar capitulo' : 'Nuevo capitulo'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                La universidad es opcional porque no todos los capitulos pertenecen a una universidad.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="chapter_type_id"
                                label="Tipo de capitulo"
                                value={chapterForm.data.chapter_type_id}
                                options={chapterTypes}
                                error={chapterForm.errors.chapter_type_id}
                                onChange={(value) => chapterForm.setData('chapter_type_id', value)}
                            />

                            <SelectField
                                id="university_id"
                                label="Universidad opcional"
                                value={chapterForm.data.university_id}
                                options={universities}
                                error={chapterForm.errors.university_id}
                                emptyLabel="Sin universidad"
                                onChange={(value) => chapterForm.setData('university_id', value)}
                            />

                            <TextField
                                id="chapter_name"
                                label="Nombre"
                                value={chapterForm.data.name}
                                error={chapterForm.errors.name}
                                onChange={(value) => chapterForm.setData('name', value)}
                            />

                            <TextareaField
                                id="chapter_description"
                                label="Descripcion"
                                value={chapterForm.data.description}
                                error={chapterForm.errors.description}
                                onChange={(value) => chapterForm.setData('description', value)}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={chapterForm.processing}>
                                {editingChapter ? 'Guardar cambios' : 'Crear capitulo'}
                            </PrimaryButton>
                            {editingChapter && (
                                <button type="button" className="glass-button" onClick={resetChapterForm}>
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <h2 className="text-xl font-black">Capitulos registrados</h2>
                            <p className="text-sm text-app-muted">{chapterTable.filteredRows.length} de {chapters.length} registros</p>
                            <TableControls
                                query={chapterTable.query}
                                onQueryChange={chapterTable.updateQuery}
                                page={chapterTable.page}
                                totalPages={chapterTable.totalPages}
                                pageSize={chapterTable.pageSize}
                                onPageSizeChange={chapterTable.updatePageSize}
                                onPageChange={chapterTable.setPage}
                                total={chapters.length}
                                filtered={chapterTable.filteredRows.length}
                                placeholder="Buscar capitulo"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Nombre</th>
                                        <th className="px-5 py-4">Tipo</th>
                                        <th className="px-5 py-4">Universidad</th>
                                        <th className="px-5 py-4">Equipos</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {chapterTable.paginatedRows.map((chapter) => (
                                        <tr key={chapter.id} className="text-sm">
                                            <td className="px-5 py-4 font-bold">{chapter.name}</td>
                                            <td className="px-5 py-4">{chapter.chapter_type?.name ?? '-'}</td>
                                            <td className="px-5 py-4">{chapter.university?.name ?? '-'}</td>
                                            <td className="px-5 py-4">{chapter.teams_count}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="file" label="Ver equipos" type="button" onClick={() => setSelectedChapterId(chapter.id)} />
                                                    <IconButton icon="edit" label="Editar capitulo" type="button" onClick={() => editChapter(chapter)} />
                                                    <IconButton icon="trash" label="Eliminar capitulo" type="button" variant="danger" onClick={() => deleteChapter(chapter)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {chapterTable.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="5" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Crea un capitulo para habilitar equipos.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[360px_1fr]">
                    <form onSubmit={submitTeam} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">
                                {editingTeam ? 'Editar equipo' : 'Nuevo equipo'}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Los creditos se conservan por equipo aunque las personas roten.
                            </p>
                        </div>

                        <div className="mt-6 space-y-4">
                            <SelectField
                                id="team_chapter_id"
                                label="Capitulo"
                                value={teamForm.data.chapter_id}
                                options={chapters}
                                error={teamForm.errors.chapter_id}
                                emptyLabel="Seleccionar capitulo"
                                onChange={(value) => {
                                    setSelectedChapterId(value);
                                    teamForm.setData('chapter_id', value);
                                }}
                            />

                            <TextField
                                id="team_name"
                                label="Nombre del equipo"
                                value={teamForm.data.name}
                                error={teamForm.errors.name}
                                onChange={(value) => teamForm.setData('name', value)}
                            />

                            <TextField
                                id="credit_balance"
                                label="Credito actual"
                                type="number"
                                step="0.01"
                                value={teamForm.data.credit_balance}
                                error={teamForm.errors.credit_balance}
                                onChange={(value) => teamForm.setData('credit_balance', value)}
                            />

                            <TextareaField
                                id="team_description"
                                label="Descripcion"
                                value={teamForm.data.description}
                                error={teamForm.errors.description}
                                onChange={(value) => teamForm.setData('description', value)}
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <PrimaryButton disabled={teamForm.processing || chapters.length === 0}>
                                {editingTeam ? 'Guardar cambios' : 'Crear equipo'}
                            </PrimaryButton>
                            {editingTeam && (
                                <button type="button" className="glass-button" onClick={resetTeamForm}>
                                    Cancelar
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="glass-panel overflow-hidden rounded-[2rem]">
                        <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                                <div>
                                    <h2 className="text-xl font-black">Equipos</h2>
                                    <p className="text-sm text-app-muted">
                                        {selectedChapter ? selectedChapter.name : 'Sin capitulo seleccionado'}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-white/30 px-4 py-2 text-sm font-black text-app-text dark:bg-white/10">
                                    Credito total: {formatMoney(sumCredits(filteredTeams))}
                                </div>
                            </div>
                            <TableControls
                                query={teamTable.query}
                                onQueryChange={teamTable.updateQuery}
                                page={teamTable.page}
                                totalPages={teamTable.totalPages}
                                pageSize={teamTable.pageSize}
                                onPageSizeChange={teamTable.updatePageSize}
                                onPageChange={teamTable.setPage}
                                total={filteredTeams.length}
                                filtered={teamTable.filteredRows.length}
                                placeholder="Buscar equipo"
                            />
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                                <thead>
                                    <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                        <th className="px-5 py-4">Equipo</th>
                                        <th className="px-5 py-4">Descripcion</th>
                                        <th className="px-5 py-4 text-right">Credito</th>
                                        <th className="px-5 py-4 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                    {teamTable.paginatedRows.map((team) => (
                                        <tr key={team.id} className="text-sm">
                                            <td className="px-5 py-4 font-bold">{team.name}</td>
                                            <td className="px-5 py-4">{team.description || '-'}</td>
                                            <td className="px-5 py-4 text-right font-black">{formatMoney(team.credit_balance)}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <IconButton icon="edit" label="Editar equipo" type="button" onClick={() => editTeam(team)} />
                                                    <IconButton icon="trash" label="Eliminar equipo" type="button" variant="danger" onClick={() => deleteTeam(team)} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {teamTable.filteredRows.length === 0 && (
                                        <tr>
                                            <td colSpan="4" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                                Aun no hay equipos para este capitulo.
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
    if (!flash.success && !flash.errors?.chapter && !flash.errors?.team) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success && (
                <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.success}
                </div>
            )}
            {flash.errors?.chapter && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.errors.chapter[0]}
                </div>
            )}
            {flash.errors?.team && (
                <div className="rounded-2xl border border-red-400/30 bg-red-400/15 px-4 py-3 text-sm font-bold text-app-text">
                    {flash.errors.team[0]}
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

function sumCredits(teams) {
    return teams.reduce((total, team) => total + Number(team.credit_balance ?? 0), 0);
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value ?? 0));
}
