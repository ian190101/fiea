import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

export default function SystemSettingsEdit({ settings = {}, storageStatus = {} }) {
    const { flash } = usePage().props;

    const form = useForm({
        primary_color: settings.primary_color ?? '#2563eb',
        secondary_color: settings.secondary_color ?? '#0f766e',
        accent_color: settings.accent_color ?? '#f59e0b',
        lock_final_invoice_by_default: Boolean(settings.lock_final_invoice_by_default),
        accounting_can_edit_summary: Boolean(settings.accounting_can_edit_summary),
        logo: null,
    });

    const previewStyle = useMemo(() => ({
        '--preview-primary': form.data.primary_color,
        '--preview-secondary': form.data.secondary_color,
        '--preview-accent': form.data.accent_color,
    }), [form.data.primary_color, form.data.secondary_color, form.data.accent_color]);

    const submit = (event) => {
        event.preventDefault();

        form.post(route('system-settings.update'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.setData('logo', null),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Configuracion</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Branding y reglas del sistema</h1>
                </div>
            }
        >
            <Head title="Configuracion" />

            <div className="max-w-6xl space-y-6">
                <FlashMessages flash={flash} />

                <section className="grid gap-6 xl:grid-cols-[420px_1fr]">
                    <form onSubmit={submit} className="glass-panel rounded-[2rem] p-5">
                        <div>
                            <h2 className="text-xl font-black">Ajustes globales</h2>
                            <p className="mt-2 text-sm leading-6 text-app-muted">
                                Estos valores se comparten con toda la interfaz y con reglas operativas de invoices.
                            </p>
                        </div>

                        <div className="mt-6 space-y-5">
                            <ColorField
                                id="primary_color"
                                label="Color primario"
                                value={form.data.primary_color}
                                error={form.errors.primary_color}
                                onChange={(value) => form.setData('primary_color', value)}
                            />
                            <ColorField
                                id="secondary_color"
                                label="Color secundario"
                                value={form.data.secondary_color}
                                error={form.errors.secondary_color}
                                onChange={(value) => form.setData('secondary_color', value)}
                            />
                            <ColorField
                                id="accent_color"
                                label="Color acento"
                                value={form.data.accent_color}
                                error={form.errors.accent_color}
                                onChange={(value) => form.setData('accent_color', value)}
                            />

                            <div>
                                <InputLabel htmlFor="logo" value="Logo institucional" />
                                <input
                                    id="logo"
                                    type="file"
                                    accept=".png,.jpg,.jpeg,.webp,.svg"
                                    onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)}
                                    className="mt-1 block w-full rounded-xl border border-app-border bg-white/70 px-3 py-2 text-sm text-app-text file:mr-3 file:rounded-xl file:border-0 file:bg-brand-primary file:px-3 file:py-2 file:text-sm file:font-black file:text-white dark:bg-stone-900/70"
                                />
                                <InputError message={form.errors.logo} className="mt-2" />
                                {settings.logo && (
                                    <p className="mt-2 text-xs font-bold text-app-muted">
                                        Actual: {settings.logo.original_name} ({settings.logo.provider})
                                    </p>
                                )}
                            </div>

                            <SwitchField
                                label="Bloquear final invoice por defecto"
                                description="Cuando esta activo, un invoice final queda protegido al crearse."
                                checked={form.data.lock_final_invoice_by_default}
                                onChange={(checked) => form.setData('lock_final_invoice_by_default', checked)}
                            />
                            <SwitchField
                                label="Contabilidad puede corregir resumen"
                                description="Si esta apagado, contabilidad solo visualiza y reconcilia."
                                checked={form.data.accounting_can_edit_summary}
                                onChange={(checked) => form.setData('accounting_can_edit_summary', checked)}
                            />
                        </div>

                        <div className="mt-6">
                            <PrimaryButton disabled={form.processing}>Guardar configuracion</PrimaryButton>
                        </div>
                    </form>

                    <div className="space-y-6">
                        <div className="glass-panel rounded-[2rem] p-5" style={previewStyle}>
                            <h2 className="text-xl font-black">Vista previa</h2>
                            <div className="mt-5 rounded-[2rem] border border-white/40 bg-white/35 p-5 shadow-lg dark:border-white/10 dark:bg-white/10">
                                <div className="flex items-center gap-4">
                                    <LogoPreview settings={settings} />
                                    <div>
                                        <p className="text-lg font-black">FIEA</p>
                                        <p className="text-sm font-bold text-app-muted">Invoices & Budget</p>
                                    </div>
                                </div>
                                <div className="mt-5 grid gap-3 sm:grid-cols-3">
                                    <PreviewChip label="Primary" color="var(--preview-primary)" />
                                    <PreviewChip label="Secondary" color="var(--preview-secondary)" />
                                    <PreviewChip label="Accent" color="var(--preview-accent)" />
                                </div>
                                <div
                                    className="mt-5 rounded-2xl px-4 py-3 text-sm font-black text-white"
                                    style={{ backgroundColor: form.data.primary_color }}
                                >
                                    Accion principal
                                </div>
                            </div>
                        </div>

                        <div className="glass-panel rounded-[2rem] p-5">
                            <h2 className="text-xl font-black">Estado actual</h2>
                            <div className="mt-4 grid gap-3 text-sm">
                                <StatusRow label="Final invoice" value={form.data.lock_final_invoice_by_default ? 'Bloqueado por defecto' : 'Editable por defecto'} />
                                <StatusRow label="Contabilidad" value={form.data.accounting_can_edit_summary ? 'Puede corregir/importar resumen' : 'Solo visualiza/reconcilia'} />
                                <StatusRow label="Ultima actualizacion" value={formatDate(settings.updated_at)} />
                                <StatusRow label="Actualizado por" value={settings.updated_by?.name ?? '-'} />
                            </div>
                        </div>

                        <StorageStatusCard storageStatus={storageStatus} />
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function StorageStatusCard({ storageStatus }) {
    const ready = Boolean(storageStatus?.ready);

    return (
        <div className="glass-panel rounded-[2rem] p-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-black">Almacenamiento</h2>
                    <p className="mt-1 text-sm text-app-muted">Aplica a logos, PDFs y comprobantes.</p>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-black uppercase ${ready ? 'bg-emerald-400/20 text-emerald-800 dark:text-emerald-100' : 'bg-amber-400/20 text-amber-800 dark:text-amber-100'}`}>
                    {ready ? 'R2 activo' : 'Fallback local'}
                </span>
            </div>

            <div className="mt-4 grid gap-3 text-sm">
                <StatusRow label="Disco configurado" value={storageStatus?.configured_disk ?? '-'} />
                <StatusRow label="Disco activo" value={storageStatus?.active_disk ?? '-'} />
                <StatusRow label="Proveedor" value={storageStatus?.provider ?? '-'} />
                <StatusRow label="Bucket R2" value={storageStatus?.bucket ?? '-'} />
                <StatusRow label="Endpoint R2" value={storageStatus?.endpoint_configured ? 'Configurado' : 'Pendiente'} />
                <StatusRow label="URL publica" value={storageStatus?.public_url_configured ? 'Configurada' : 'Pendiente'} />
                <StatusRow label="Adaptador S3" value={storageStatus?.adapter_installed ? 'Instalado' : 'No instalado'} />
            </div>

            {!ready && (
                <p className="mt-4 rounded-2xl bg-white/30 px-4 py-3 text-sm font-bold text-app-muted dark:bg-white/10">
                    Para activar R2 define credenciales Cloudflare y asegurate de tener el adaptador S3 instalado en Composer.
                </p>
            )}
        </div>
    );
}

function FlashMessages({ flash }) {
    const error = flash.errors?.primary_color?.[0]
        ?? flash.errors?.secondary_color?.[0]
        ?? flash.errors?.accent_color?.[0]
        ?? flash.errors?.logo?.[0];

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

function ColorField({ id, label, value, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-1 grid grid-cols-[54px_1fr] gap-3">
                <input
                    id={id}
                    type="color"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    className="h-11 w-full rounded-xl border border-app-border bg-white/70 p-1 dark:bg-stone-900/70"
                />
                <input
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    className="block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                />
            </div>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function SwitchField({ label, description, checked, onChange }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-2xl bg-white/30 p-4 dark:bg-white/10">
            <div>
                <p className="font-black">{label}</p>
                <p className="mt-1 text-sm text-app-muted">{description}</p>
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`flex h-8 w-14 shrink-0 items-center rounded-full p-1 transition ${checked ? 'bg-brand-primary' : 'bg-stone-400/60'}`}
            >
                <span className={`h-6 w-6 rounded-full bg-white shadow transition ${checked ? 'translate-x-6' : 'translate-x-0'}`} />
            </button>
        </div>
    );
}

function LogoPreview({ settings }) {
    if (settings.logo?.public_url) {
        return <img src={settings.logo.public_url} alt="FIEA" className="h-14 w-14 rounded-2xl object-contain" />;
    }

    return (
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-primary text-lg font-black text-white">
            F
        </div>
    );
}

function PreviewChip({ label, color }) {
    return (
        <div className="rounded-2xl bg-white/40 p-3 dark:bg-white/10">
            <div className="h-8 rounded-xl" style={{ backgroundColor: color }} />
            <p className="mt-2 text-xs font-black uppercase text-app-muted">{label}</p>
        </div>
    );
}

function StatusRow({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-2xl bg-white/30 px-4 py-3 dark:bg-white/10">
            <span className="font-bold text-app-muted">{label}</span>
            <span className="text-right font-black">{value}</span>
        </div>
    );
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
