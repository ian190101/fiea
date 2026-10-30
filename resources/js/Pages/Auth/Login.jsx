import HelpTooltip from '@/Components/HelpTooltip';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import SceneButton from '@/Components/Glass/SceneButton';
import LiquidToggle from '@/Components/Glass/LiquidToggle';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function Login({ status, canResetPassword }) {
    const formRef = useRef(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
            onError: () => {
                requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus());
            },
        });
    };

    return (
        <GuestLayout wide>
            <Head title="Iniciar sesión" />

            <div className="login-grid">
            <div className="login-intro">
            <div className="mb-7">
                <p className="login-eyebrow">ACCESO SEGURO · FIEA</p>
                <h1 className="text-2xl font-bold tracking-tight text-app-text">
                    Bienvenido de nuevo
                </h1>
                <p className="mt-2 text-sm leading-6 text-app-muted">
                    Accede con tu nombre de usuario para gestionar presupuestos, invoices y conciliaciones.
                </p>
            </div>

            <div className="login-optical-mark" aria-hidden="true"><span>F</span><i /><i /></div>
            <p className="mt-4 text-xs text-app-muted">Tu operación financiera, en un solo lugar.</p>
            </div>
            <div className="login-form-column">

            {status && (
                <div role="status" className="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            {Object.keys(errors).length > 0 && <p role="alert" className="mb-4 text-sm font-semibold text-red-700 dark:text-red-200">Revisa los campos marcados antes de continuar.</p>}
            <form id="login-form" ref={formRef} onSubmit={submit}>
                <div>
                    <div className="flex items-center gap-1"><InputLabel help={false} htmlFor="username" value="Usuario o correo" /><HelpTooltip label="Usuario o correo" text="Escribe tu nombre de usuario, por ejemplo ian, o el correo asociado a tu cuenta." /></div>

                    <TextInput
                        id="username"
                        aria-invalid={Boolean(errors.username)}
                        aria-describedby={errors.username ? "username-error" : undefined}
                        name="username"
                        value={data.username}
                        className="login-field mt-1 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('username', e.target.value)}
                    />

                    <InputError id="username-error" message={errors.username} className="mt-2" />
                </div>

                <div className="mt-4">
                    <div className="flex items-center gap-1"><InputLabel help={false} htmlFor="password" value="Contraseña" /><HelpTooltip label="Contraseña" text="Introduce la contraseña de tu cuenta. Distingue mayúsculas y minúsculas." /></div>

                    <TextInput
                        id="password"
                        aria-invalid={Boolean(errors.password)}
                        aria-describedby={errors.password ? "password-error" : undefined}
                        type="password"
                        name="password"
                        value={data.password}
                        className="login-field mt-1 block w-full"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError id="password-error" message={errors.password} className="mt-2" />
                </div>

                <div className="mt-5 flex items-center gap-2">
                    <LiquidToggle name="remember" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} label="Mantener sesión" />
                    <HelpTooltip label="Mantener sesión" text="Conserva el acceso en este navegador. Úsalo solo en dispositivos personales." />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="rounded-md text-sm text-app-muted underline hover:text-app-text focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2"
                        >
                            Olvidé mi contraseña
                        </Link>
                    )}

                    <SceneButton type="submit" form="login-form" className="login-submit ms-4" disabled={processing} aria-busy={processing}>
                        {processing && <span className="login-spinner" aria-hidden="true" />}
                        {processing ? 'Entrando…' : 'Entrar'}
                    </SceneButton>
                </div>
            </form>
            </div>
            </div>
        </GuestLayout>
    );
}
