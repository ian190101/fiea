import { forwardRef } from 'react';

export const icons = {
    arrowRight: 'M5 12h14m-6-6 6 6-6 6',
    check: 'M20 6 9 17l-5-5',
    chevronLeft: 'M15 6l-6 6 6 6',
    chevronRight: 'M9 6l6 6-6 6',
    download: 'M12 3v11m0 0 4-4m-4 4-4-4M5 21h14',
    edit: 'M16.862 4.487l1.688-1.688a1.875 1.875 0 112.652 2.652L8.25 18.403 4.5 19.5l1.097-3.75L16.862 4.487z',
    file: 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z M14 2v6h6 M8 13h8 M8 17h6',
    info: 'M12 11v5 M12 8h.01 M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    search: 'M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z',
    trash: 'M3 6h18 M8 6V4h8v2 M6 6l1 16h10l1-16',
    warning: 'M12 4 3 20h18L12 4z M12 9v5 M12 17h.01',
    x: 'M6 6l12 12M18 6L6 18',
};

export function SvgIcon({ name, className = 'h-4 w-4' }) {
    return (
        <svg
            aria-hidden="true"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2"
            viewBox="0 0 24 24"
        >
            <path d={icons[name] ?? icons.file} />
        </svg>
    );
}

const IconButton = forwardRef(function IconButton({
    as: Component = 'button',
    icon,
    label,
    className = '',
    variant = 'default',
    ...props
}, ref) {
    const variants = {
        default: 'text-app-text hover:bg-white/65 dark:hover:bg-white/15',
        danger: 'text-red-600 hover:bg-red-500/15 dark:text-red-300',
        primary: 'bg-brand-primary text-white hover:bg-brand-primary/90',
    };

    return (
        <Component
            ref={ref}
            aria-label={label}
            title={label}
            className={`inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-white/45 bg-white/35 shadow-sm transition hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 focus:ring-offset-transparent dark:border-white/10 dark:bg-white/10 ${variants[variant] ?? variants.default} ${className}`}
            {...props}
        >
            <SvgIcon name={icon} />
            <span className="sr-only">{label}</span>
        </Component>
    );
});

export default IconButton;
