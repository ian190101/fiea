import ApplicationLogo from '@/Components/ApplicationLogo';
import ThemeToggle from '@/Components/ThemeToggle';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { applyTheme, hexToRgb } from '@/Utils/theme';

export default function GuestLayout({ children }) {
    const branding = usePage().props.branding;
    const cssVariables = useMemo(
        () => ({
            '--brand-primary': hexToRgb(branding.primaryColor),
            '--brand-secondary': hexToRgb(branding.secondaryColor),
            '--brand-accent': hexToRgb(branding.accentColor),
        }),
        [branding],
    );

    useEffect(() => {
        applyTheme(localStorage.getItem('theme_preference') ?? 'light');
    }, []);

    return (
        <div
            className="app-shell relative flex min-h-screen items-center justify-center overflow-hidden bg-app-bg px-4 py-8 text-app-text"
            style={cssVariables}
        >
            <div className="app-ambient-bg pointer-events-none fixed inset-0 z-0" />
            <div className="absolute right-5 top-5 z-20">
                <ThemeToggle />
            </div>

            <div className="relative z-10 w-full max-w-md">
                <div>
                    <Link href="/" className="mb-6 flex items-center gap-3">
                        {branding.logoUrl ? (
                            <img src={branding.logoUrl} alt="FIEA" className="h-12 w-12 rounded-2xl object-contain" />
                        ) : (
                            <ApplicationLogo className="h-12 w-12 fill-current text-brand-primary" />
                        )}
                        <div>
                            <p className="text-lg font-bold tracking-tight">FIEA</p>
                            <p className="text-xs font-semibold uppercase text-app-muted">Sistema de invoices</p>
                        </div>
                    </Link>

                    <div className="glass-panel w-full rounded-[2rem] px-7 py-7">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
