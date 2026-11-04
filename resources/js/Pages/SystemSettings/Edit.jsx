import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import FinancialActionSheet from '@/Components/FinancialActionSheet';
import { useUnsavedChangesGuard } from '@/Utils/useUnsavedChangesGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function SystemSettingsEdit({ settings = {}, storageStatus = {}, logoUrl = null, mailSettings = {} }) {
    const { flash } = usePage().props;

    const form = useForm({
        primary_color: settings.primary_color ?? '#3960a3',
        secondary_color: settings.secondary_color ?? '#f78e3c',
        accent_color: settings.accent_color ?? '#007145',
        logo: null,
    });
    const mailForm = useForm({ account: mailSettings.account ?? '', from_name: mailSettings.from_name ?? 'FIEA',
        app_password: '', enabled: mailSettings.enabled ?? true, version: mailSettings.version ?? 0 });
    useEffect(() => {
        const current = { account: mailSettings.account ?? '', from_name: mailSettings.from_name ?? 'FIEA',
            app_password: '', enabled: mailSettings.enabled ?? true, version: mailSettings.version ?? 0 };
        mailForm.setData(current);
        mailForm.setDefaults(current);
    }, [mailSettings.version]);
    const [uploadedLogoUrl, setUploadedLogoUrl] = useState(null);
    useEffect(() => {
        if (!form.data.logo) { setUploadedLogoUrl(null); return undefined; }
        const url = URL.createObjectURL(form.data.logo);
        setUploadedLogoUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [form.data.logo]);
    const guard = useUnsavedChangesGuard((form.isDirty || mailForm.isDirty) && !form.processing && !mailForm.processing);
    // El naranja es institucional y decorativo; los botones con texto blanco utilizan el primario.
    const contrastProblems = ['primary_color']
        .filter((field) => contrastAgainstWhite(form.data[field]) < 4.5);

    const previewStyle = useMemo(() => ({
        '--preview-primary': form.data.primary_color,
        '--preview-secondary': form.data.secondary_color,
        '--preview-accent': form.data.accent_color,
    }), [form.data.primary_color, form.data.secondary_color, form.data.accent_color]);
    const brandingPreview = useMemo(() => ({ primaryColor: form.data.primary_color, secondaryColor: form.data.secondary_color, accentColor: form.data.accent_color }), [form.data.primary_color, form.data.secondary_color, form.data.accent_color]);

    const submit = (event) => {
        event.preventDefault();
        if (contrastProblems.length) return;

        form.post(route('system-settings.update'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { form.setData('logo', null); form.setDefaults({ ...form.data, logo: null }); },
        });
    };

    return (
        <AuthenticatedLayout
            brandingPreview={brandingPreview}
            header={
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-semibold uppercase tracking-wide text-brand-primary">Configuracion</p>
                    <h1 className="text-3xl font-bold tracking-tight text-app-text">Branding y reglas del sistema</h1>
                </div>
            }
        >
            <Head title="Configuracion" />
            <FinancialActionSheet {...guard.dialogProps} />

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
                                    label="Color terciario"
                                value={form.data.accent_color}
                                error={form.errors.accent_color}
                                onChange={(value) => form.setData('accent_color', value)}
                            />

                            <div>
                                <InputLabel htmlFor="logo" value="Logo institucional" />
                                <input
                                    id="logo"
                                    type="file"
                                    accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                                    aria-invalid={Boolean(form.errors.logo)}
                                    aria-describedby={form.errors.logo ? 'logo-error' : undefined}
                                    onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)}
                                    className="mt-1 block w-full rounded-xl border border-app-border bg-white/70 px-3 py-2 text-sm text-app-text file:mr-3 file:rounded-xl file:border-0 file:bg-brand-primary file:px-3 file:py-2 file:text-sm file:font-black file:text-white dark:bg-stone-900/70"
                                />
                                <InputError id="logo-error" message={form.errors.logo} className="mt-2" />
                                {settings.logo && (
                                    <p className="mt-2 text-xs font-bold text-app-muted">
                                        Actual: {settings.logo.original_name} ({settings.logo.provider})
                                    </p>
                                )}
                            </div>

                            <p className="text-sm">Los borradores permiten revisión. Aprobar un documento congela sus importes y su evidencia. Contabilidad registra sus conciliaciones por separado.</p>
                        </div>

                        <div className="mt-6">
                            {contrastProblems.length > 0 && <p role="alert" className="mb-3 text-sm font-semibold text-red-700 dark:text-red-200">Elige un tono más oscuro para {contrastProblems.map((field) => field === 'primary_color' ? 'el color primario' : 'el color secundario').join(' y ')} para mantener legibles los botones.</p>}
                            <PrimaryButton disabled={form.processing || contrastProblems.length > 0}>Guardar configuracion</PrimaryButton>
                        </div>
                    </form>

                    <div className="space-y-6">
                        <div className="glass-panel rounded-[2rem] p-5" style={previewStyle}>
                            <h2 className="text-xl font-black">Vista previa</h2>
                            <div className="mt-5 rounded-[2rem] border border-white/40 bg-white/35 p-5 shadow-lg dark:border-white/10 dark:bg-white/10">
                                <div className="flex items-center gap-4">
                                    <LogoPreview settings={{ ...settings, logo: { ...settings.logo, public_url: uploadedLogoUrl ?? logoUrl } }} />
                                    <div>
                                        <p className="text-lg font-black">FIEA</p>
                                        <p className="text-sm font-bold text-app-muted">Invoices & Budget</p>
                                    </div>
                                </div>
                                <div className="mt-5 grid gap-3 sm:grid-cols-3">
                                    <PreviewChip label="Primario" color="var(--preview-primary)" />
                                    <PreviewChip label="Secundario" color="var(--preview-secondary)" />
                                    <PreviewChip label="Terciario" color="var(--preview-accent)" />
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
                                <StatusRow label="Documentos" value="Protegidos al aprobar" />
                                <StatusRow label="Contabilidad" value="Conciliación independiente" />
                                <StatusRow label="Ultima actualizacion" value={formatDate(settings.updated_at)} />
                                <StatusRow label="Actualizado por" value={settings.updated_by?.name ?? '-'} />
                            </div>
                        </div>

                        <MailSettingsPanel form={mailForm} passwordConfigured={mailSettings.password_configured} />
                        <StorageStatusCard storageStatus={storageStatus} />
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function MailSettingsPanel({ form, passwordConfigured }) {
    const [sendingTest, setSendingTest] = useState(false);
    const submit = event => {
        event.preventDefault();
        form.post(route('mail-settings.update'), { preserveScroll: true, onFinish: () => form.reset('app_password') });
    };
    return <section className="glass-panel rounded-[2rem] p-5" aria-labelledby="gmail-settings-title">
        <h2 id="gmail-settings-title" className="text-xl font-black">Correo de Gmail</h2>
        <p className="mt-2 text-sm text-app-muted">Configura la cuenta emisora sin editar código. Los destinatarios de las facturas se eligen desde Contactos.</p>
        <form onSubmit={submit} className="mt-5 space-y-4">
            <div><InputLabel htmlFor="mail_account" value="Cuenta de Gmail o Google Workspace" />
                <input id="mail_account" type="email" required autoComplete="email" className="ios-field mt-1 w-full"
                    value={form.data.account} onChange={event => form.setData('account', event.target.value)} />
                <InputError message={form.errors.account} /></div>
            <div><InputLabel htmlFor="mail_from_name" value="Nombre del remitente" />
                <input id="mail_from_name" required maxLength={100} className="ios-field mt-1 w-full"
                    value={form.data.from_name} onChange={event => form.setData('from_name', event.target.value)} />
                <InputError message={form.errors.from_name} /></div>
            <div><InputLabel htmlFor="mail_app_password" value="Contraseña de aplicación" />
                <input id="mail_app_password" type="password" autoComplete="new-password" maxLength={24}
                    className="ios-field mt-1 w-full" value={form.data.app_password}
                    aria-describedby="mail-password-help" onChange={event => form.setData('app_password', event.target.value)} />
                <p id="mail-password-help" className="mt-2 text-xs text-app-muted">{passwordConfigured ? 'Ya hay una contraseña guardada. Deja el campo vacío para conservarla.' : 'Introduce la contraseña de aplicación generada en Google.'} Se guarda cifrada y nunca se muestra de nuevo.</p>
                <InputError message={form.errors.app_password} /></div>
            <label className="flex items-center gap-3 text-sm"><input type="checkbox" checked={form.data.enabled}
                onChange={event => form.setData('enabled', event.target.checked)} /> Envío de facturas habilitado</label>
            <InputError message={form.errors.version} />
            <PrimaryButton type="submit" disabled={form.processing}>Guardar y comprobar conexión</PrimaryButton>
        </form>
        <button type="button" className="glass-button mt-4 px-4 py-2" disabled={sendingTest || form.isDirty || !passwordConfigured}
            onClick={() => router.post(route('mail-settings.test'), {}, { preserveScroll: true,
                onStart: () => setSendingTest(true), onFinish: () => setSendingTest(false) })}>
            {sendingTest ? 'Enviando prueba…' : 'Enviar correo de prueba'}</button>
        <p className="mt-2 text-xs text-app-muted">La prueba se envía únicamente a la cuenta guardada y lleva el aviso PRUEBA.</p>
    </section>;
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
                    {ready ? 'R2 activo' : 'Almacenamiento local'}
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
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className="h-11 w-full rounded-xl border border-app-border bg-white/70 p-1 dark:bg-stone-900/70"
                />
                <input
                    aria-label={`${label}, valor hexadecimal`}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className="block w-full rounded-xl border-app-border bg-white/70 text-app-text shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:bg-stone-900/70"
                />
            </div>
            <InputError id={`${id}-error`} message={error} className="mt-2" />
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

function contrastAgainstWhite(hex) {
    if (!/^#[0-9a-fA-F]{6}$/.test(hex ?? '')) return 0;
    const channels = [1, 3, 5].map((start) => Number.parseInt(hex.slice(start, start + 2), 16) / 255);
    const linear = channels.map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
    return 1.05 / (0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2] + 0.05);
}
