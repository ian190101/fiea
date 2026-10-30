export function hexToRgb(hex, fallback = '2563eb') {
    const safe = typeof hex === 'string' && /^#?[0-9a-f]{6}$/i.test(hex) ? hex : fallback;
    const normalized = safe.replace('#', '');
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

let systemMedia = null;
let systemListener = null;

export function applyTheme(theme) {
    if (systemMedia && systemListener) {
        systemMedia.removeEventListener('change', systemListener);
        systemMedia = null;
        systemListener = null;
    }
    const resolvedTheme = resolveTheme(theme);

    document.documentElement.classList.toggle('dark', resolvedTheme === 'dark');
    document.documentElement.dataset.theme = resolvedTheme;
    document.documentElement.style.colorScheme = resolvedTheme === 'dark' ? 'dark' : 'light';
    if (theme === 'system') {
        systemMedia = window.matchMedia('(prefers-color-scheme: dark)');
        systemListener = () => applyTheme('system');
        systemMedia.addEventListener('change', systemListener);
    }
}
