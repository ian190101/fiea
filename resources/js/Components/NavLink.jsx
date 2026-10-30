import { Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            aria-current={active ? 'page' : undefined}
            className={
                'inline-flex min-h-11 items-center border-b-2 px-2 text-sm font-medium text-app-text transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary ' +
                (active
                    ? 'border-brand-primary'
                    : 'border-transparent hover:border-brand-primary/50') +
                className
            }
        >
            {children}
        </Link>
    );
}
