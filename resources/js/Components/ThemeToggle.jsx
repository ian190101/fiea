import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { applyTheme, resolveTheme } from '@/Utils/theme';
import { readPreference, writePreference } from '@/Utils/preferences';

export default function ThemeToggle({ className = '', compact = false }) {
    const user = usePage().props.auth.user;
    const initialTheme = user?.theme_preference ?? readPreference('theme_preference', 'light');
    const [currentTheme, setCurrentTheme] = useState(initialTheme);
    const [saving, setSaving] = useState(false);
    useEffect(() => { setCurrentTheme(initialTheme); }, [initialTheme]);
    const [systemDark, setSystemDark] = useState(() => window.matchMedia('(prefers-color-scheme: dark)').matches);
    const isDark = currentTheme === 'system' ? systemDark : resolveTheme(currentTheme) === 'dark';

    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const update = () => setSystemDark(media.matches);
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);

    const updateTheme = () => {
        const nextTheme = isDark ? 'light' : 'dark';

        setCurrentTheme(nextTheme);
        writePreference('theme_preference', nextTheme);
        applyTheme(nextTheme);

        if (!user) {
            return;
        }

        setSaving(true);
        // Axios toma XSRF-TOKEN de la cookie actual, incluso tras regenerar la sesión al entrar.
        window.axios.patch(route('theme-preference.update'), { theme_preference: nextTheme }, { headers: { Accept: 'application/json' } }).then((response) => {
            if (response.status !== 204) throw new Error('El servidor no confirmó el cambio de tema.');
        }).catch(() => {
            setCurrentTheme(currentTheme);
            writePreference('theme_preference', currentTheme);
            applyTheme(currentTheme);
        }).finally(() => setSaving(false));
    };

    return (
        <button
            type="button"
            onClick={updateTheme}
            disabled={saving}
            aria-busy={saving}
            aria-label={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
            role="switch"
            aria-checked={isDark}
            className={`${compact ? 'ios-switch-compact' : 'ios-switch'} ${isDark ? 'ios-switch-on' : ''} ${className}`}
        >
            {compact ? (
                <SwitchIcon isDark={isDark} className="h-4 w-4" />
            ) : (
                <span className="ios-switch-track">
                    <span className="ios-switch-glow" />
                    <span className="ios-switch-thumb">
                        <SwitchIcon isDark={isDark} className="h-4 w-4" />
                    </span>
                </span>
            )}
        </button>
    );
}

function SwitchIcon({ isDark, className }) {
    return isDark ? (
        <svg viewBox="0 0 24 24" className={className}>
            <path
                fill="currentColor"
                d="M20.6 14.4A7.9 7.9 0 0 1 9.6 3.4a.7.7 0 0 0-.7-.9A9.8 9.8 0 1 0 21.5 15.1a.7.7 0 0 0-.9-.7Z"
            />
        </svg>
    ) : (
        <svg viewBox="0 0 24 24" className={className}>
            <path
                fill="currentColor"
                d="M12 18a6 6 0 1 1 0-12 6 6 0 0 1 0 12Zm0-15a1 1 0 0 1-1-1V1a1 1 0 1 1 2 0v1a1 1 0 0 1-1 1Zm0 22a1 1 0 0 1-1-1v-1a1 1 0 1 1 2 0v1a1 1 0 0 1-1 1ZM4.2 5.6l-.7-.7a1 1 0 0 1 1.4-1.4l.7.7a1 1 0 1 1-1.4 1.4Zm15.6 15.6-.7-.7a1 1 0 0 1 1.4-1.4l.7.7a1 1 0 0 1-1.4 1.4ZM2 13H1a1 1 0 1 1 0-2h1a1 1 0 1 1 0 2Zm22 0h-1a1 1 0 1 1 0-2h1a1 1 0 1 1 0 2ZM4.9 20.5a1 1 0 0 1-1.4-1.4l.7-.7a1 1 0 0 1 1.4 1.4l-.7.7Zm15.6-15.6a1 1 0 0 1-1.4-1.4l.7-.7a1 1 0 1 1 1.4 1.4l-.7.7Z"
            />
        </svg>
    );
}
