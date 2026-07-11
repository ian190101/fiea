import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { applyTheme } from '@/Utils/theme';

export default function ThemeToggle({ className = '', compact = false }) {
    const user = usePage().props.auth.user;
    const initialTheme = user?.theme_preference ?? localStorage.getItem('theme_preference') ?? 'light';
    const [currentTheme, setCurrentTheme] = useState(initialTheme);
    const isDark = currentTheme === 'dark';

    const updateTheme = () => {
        const nextTheme = isDark ? 'light' : 'dark';

        setCurrentTheme(nextTheme);
        localStorage.setItem('theme_preference', nextTheme);
        applyTheme(nextTheme);

        if (!user) {
            return;
        }

        fetch(route('theme-preference.update'), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            keepalive: true,
            body: JSON.stringify({ theme_preference: nextTheme }),
        }).catch(() => {
            setCurrentTheme(currentTheme);
            localStorage.setItem('theme_preference', currentTheme);
            applyTheme(currentTheme);
        });
    };

    return (
        <button
            type="button"
            onClick={updateTheme}
            aria-label={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
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
