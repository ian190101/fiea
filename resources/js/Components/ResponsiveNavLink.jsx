import { Link } from '@inertiajs/react';

export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            aria-current={active ? 'page' : undefined}
            className={`flex min-h-11 w-full items-center border-l-4 py-2 pe-4 ps-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary ${
                active
                    ? 'border-brand-primary bg-brand-primary/15 text-app-text'
                    : 'border-transparent text-app-muted hover:bg-white/35 hover:text-app-text'
            } text-base font-medium transition duration-150 ease-in-out ${className}`}
        >
            {children}
        </Link>
    );
}
