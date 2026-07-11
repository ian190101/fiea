export function hexToRgb(hex, fallback = '2563eb') {
    const normalized = (hex || fallback).replace('#', '');
    const value = parseInt(normalized, 16);

    return `${(value >> 16) & 255} ${(value >> 8) & 255} ${value & 255}`;
}

export function resolveTheme(theme) {
    if (theme === 'system') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    return theme === 'dark' ? 'dark' : 'light';
}

export function applyTheme(theme) {
    const resolvedTheme = resolveTheme(theme);

    document.documentElement.classList.toggle('dark', resolvedTheme === 'dark');
    document.documentElement.style.colorScheme = resolvedTheme === 'dark' ? 'dark' : 'light';
}
