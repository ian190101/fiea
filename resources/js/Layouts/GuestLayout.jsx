import ApplicationLogo from '@/Components/ApplicationLogo';
import ThemeToggle from '@/Components/ThemeToggle';
import WorkflowGuideCard from '@/Components/WorkflowGuideCard';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { applyTheme, hexToRgb } from '@/Utils/theme';
import { readPreference } from '@/Utils/preferences';
import GlassScene, { MacroGlass } from '@/Components/Glass/GlassScene';

export default function GuestLayout({ children, wide = false }) {
    const branding = usePage().props.branding;
    const { liquidGlass } = usePage().props;
    const cssVariables = useMemo(
        () => ({
            '--brand-primary': hexToRgb(branding.primaryColor),
            '--brand-secondary': hexToRgb(branding.secondaryColor),
            '--brand-accent': hexToRgb(branding.accentColor),
        }),
        [branding],
    );

    useEffect(() => {
        applyTheme(readPreference('theme_preference', 'light'));
    }, []);

    const path = window.location.pathname;
    const guestGuide = path === '/login' ? null : path === '/' ? {
        title: 'Comienza en FIEA', summary: 'Abre el acceso para entrar al sistema.',
        steps: ['Selecciona Iniciar sesión.', 'Introduce tu cuenta para abrir el panel.'],
    } : path.includes('forgot-password') ? {
        title: 'Recupera tu acceso', summary: 'Solicita un enlace de recuperación para tu correo registrado.',
        steps: ['Escribe el correo asociado a tu cuenta.', 'Abre el enlace que recibirás y crea una contraseña nueva.'],
    } : {
        title: 'Completa este paso de seguridad', summary: 'Sigue las indicaciones del formulario para continuar.',
        steps: ['Revisa los datos solicitados.', 'Envía el formulario y confirma el resultado mostrado.'],
    };

    return (
        <GlassScene profiles={liquidGlass}
            className="app-shell guest-scene text-app-text"
            style={cssVariables}
        >
            <div className="absolute right-5 top-5 z-20">
                <ThemeToggle />
            </div>

            <main id="main-workspace" className={`guest-brand relative z-10 w-full ${wide ? 'max-w-4xl' : 'max-w-md'}`}>
                    <Link href="/" className="flex items-center gap-3">
                        {branding.logoUrl ? (
                            <img crossOrigin="anonymous" src={branding.logoUrl} alt="FIEA" width="48" height="48" className="h-12 w-12 rounded-2xl object-contain" />
                        ) : (
                            <ApplicationLogo className="h-12 w-12 fill-current text-brand-primary" />
                        )}
                        <div>
                            <p className="text-lg font-bold tracking-tight">FIEA</p>
                            <p className="text-xs font-semibold uppercase text-app-muted">Sistema de invoices</p>
                        </div>
                    </Link>

            </main>
                    <MacroGlass className={`login-card w-full px-7 py-7 ${wide ? 'max-w-4xl' : 'max-w-md'}`} config={{ floating: false, button: false }}>
                        {children}
                        {guestGuide && <div className="mt-5"><WorkflowGuideCard guideKey={path} userId="invitado" defaultOpen={false}
                            title={guestGuide.title} summary={guestGuide.summary}
                            steps={guestGuide.steps.map((step, index) => ({ id: `${path}-${index}`, text: step }))} /></div>}
                    </MacroGlass>
        </GlassScene>
    );
}
