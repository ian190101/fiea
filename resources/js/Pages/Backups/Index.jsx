import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TableControls from '@/Components/TableControls';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocalTable } from '@/Utils/useLocalTable';
import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

const statusStyles = {
    completed: 'bg-emerald-400/15 text-emerald-800 dark:text-emerald-100',
    running: 'bg-sky-400/15 text-sky-800 dark:text-sky-100',
    failed: 'bg-red-400/15 text-red-800 dark:text-red-100',
};

const statusLabels = {
    completed: 'Completado',
    running: 'En proceso',
    failed: 'Fallido',
};

export default function BackupIndex({ backups = [] }) {
    const { flash } = usePage().props;
    const searchableText = useCallback((backup) => [
        backup.type,
        backup.status,
        backup.storage_file?.original_name,
        backup.created_by?.name,
        backup.created_at,
    ].filter(Boolean).join(' '), []);
    const table = useLocalTable(backups, searchableText, 10);

    const completedBackups = useMemo(() => backups.filter((backup) => backup.status === 'completed'), [backups]);
    const latestBackup = completedBackups[0] ?? null;
    const totalSize = useMemo(
        () => completedBackups.reduce((total, backup) => total + Number(backup.size_bytes ?? 0), 0),
        [completedBackups],
    );

    const createBackup = () => {
        router.post(route('backups.store'), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Backups</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Respaldos de base de datos</h1>
                </div>
            }
        >
            <Head title="Backups" />

            <div className="max-w-7xl space-y-6">
                <FlashMessages flash={flash} />

                <section className="grid gap-4 md:grid-cols-3">
                    <MetricCard label="Backups completados" value={completedBackups.length} />
                    <MetricCard label="Ultimo backup" value={latestBackup ? formatDateTime(latestBackup.completed_at) : '-'} />
                    <MetricCard label="Tamano acumulado" value={formatBytes(totalSize)} />
                </section>

                <section className="glass-panel rounded-[2rem] p-5">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-xl font-black">Generar backup SQL</h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-app-muted">
                                El backup se guarda en el almacenamiento activo del sistema. Si Cloudflare R2 esta configurado, se sube ahi; si no, queda en local.
                            </p>
                        </div>
                        <PrimaryButton type="button" onClick={createBackup}>
                            Generar backup
                        </PrimaryButton>
                    </div>
                </section>

                <section className="glass-panel overflow-hidden rounded-[2rem]">
                    <div className="space-y-4 border-b border-white/30 px-5 py-5 dark:border-white/10">
                        <div>
                            <h2 className="text-xl font-black">Historial</h2>
                            <p className="text-sm text-app-muted">{backups.length} ejecuciones registradas</p>
                        </div>
                        <TableControls
                            query={table.query}
                            onQueryChange={table.updateQuery}
                            page={table.page}
                            totalPages={table.totalPages}
                            pageSize={table.pageSize}
                            onPageSizeChange={table.updatePageSize}
                            onPageChange={table.setPage}
                            total={backups.length}
                            filtered={table.filteredRows.length}
                            placeholder="Buscar backup"
                        />
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-white/30 dark:divide-white/10">
                            <thead>
                                <tr className="text-left text-xs font-black uppercase tracking-wide text-app-muted">
                                    <th className="px-5 py-4">Archivo</th>
                                    <th className="px-5 py-4">Estado</th>
                                    <th className="px-5 py-4">Disco</th>
                                    <th className="px-5 py-4">Generado por</th>
                                    <th className="px-5 py-4">Fecha</th>
                                    <th className="px-5 py-4 text-right">Tamano</th>
                                    <th className="px-5 py-4 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/25 dark:divide-white/10">
                                {table.paginatedRows.map((backup) => (
                                    <tr key={backup.id} className="text-sm">
                                        <td className="px-5 py-4">
                                            <div className="font-bold">{backup.storage_file?.original_name ?? 'Sin archivo'}</div>
                                            <div className="max-w-xs truncate text-xs text-app-muted">{backup.checksum ?? backup.error_message ?? '-'}</div>
                                        </td>
                                        <td className="px-5 py-4">
                                            <span className={`rounded-full px-3 py-1 text-xs font-black uppercase ${statusStyles[backup.status] ?? statusStyles.failed}`}>
                                                {statusLabels[backup.status] ?? backup.status}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4">{backup.disk ?? '-'}</td>
                                        <td className="px-5 py-4">{backup.created_by?.name ?? 'Sistema'}</td>
                                        <td className="px-5 py-4">{formatDateTime(backup.created_at)}</td>
                                        <td className="px-5 py-4 text-right font-black">{formatBytes(backup.size_bytes)}</td>
                                        <td className="px-5 py-4">
                                            <div className="flex justify-end">
                                                {backup.status === 'completed' && (
                                                    <IconButton as="a" href={route('backups.show', backup.id)} icon="download" label="Descargar backup" />
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {table.filteredRows.length === 0 && (
                                    <tr>
                                        <td colSpan="7" className="px-5 py-10 text-center text-sm font-semibold text-app-muted">
                                            Aun no hay backups para este filtro.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function FlashMessages({ flash }) {
    const error = flash.errors?.backup?.[0];

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

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
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
