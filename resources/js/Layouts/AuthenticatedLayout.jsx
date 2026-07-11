import ApplicationLogo from '@/Components/ApplicationLogo';
import ThemeToggle from '@/Components/ThemeToggle';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { applyTheme, hexToRgb } from '@/Utils/theme';

const navigation = [
    { label: 'Panel', route: 'dashboard', icon: DashboardIcon },
    { label: 'Notificaciones', route: 'notifications.index', icon: NotificationIcon },
    { label: 'Catalogos', route: 'catalogs.index', icon: CatalogIcon, permissions: ['catalogs.view', 'catalogs.manage'] },
    { label: 'Capitulos', route: 'chapters.index', icon: ChapterIcon, permissions: ['chapters.view', 'chapters.manage'] },
    { label: 'Contactos', route: 'contacts.index', icon: ContactIcon, permissions: ['contacts.view', 'contacts.manage'] },
    { label: 'Proyectos', route: 'projects.index', icon: ProjectIcon, permissions: ['projects.view', 'projects.manage'] },
    { label: 'Viajes', route: 'trip-phases.index', icon: TripIcon, permissions: ['trip_phases.view', 'trip_phases.manage'] },
    { label: 'Budget', route: 'estimated-expenses.index', icon: BudgetIcon, permissions: ['budgets.view', 'budgets.manage'] },
    { label: 'Invoices', route: 'invoices.index', icon: InvoiceIcon, permissions: ['invoices.view', 'invoices.manage'] },
    { label: 'Correos', route: 'invoice-emails.index', icon: EmailIcon, permissions: ['invoice_emails.view', 'invoice_emails.manage'] },
    { label: 'Gastos', route: 'actual-expenses.index', icon: ExpenseIcon, permissions: ['actual_expenses.view', 'actual_expenses.manage'] },
    { label: 'Recibos', route: 'receipts.index', icon: ReceiptIcon, permissions: ['receipts.view', 'receipts.manage'] },
    { label: 'Contabilidad', route: 'accounting.index', icon: AccountingIcon, permissions: ['accounting.view', 'accounting.manage'] },
    { label: 'Reportes', route: 'reports.index', icon: ReportIcon, permissions: ['reports.view'] },
    { label: 'Alertas', route: 'alerts.index', icon: AlertIcon, permissions: ['alerts.view'] },
    { label: 'Auditoria', route: 'audit-logs.index', icon: AuditIcon, permissions: ['audit_logs.view'] },
    { label: 'Estado', route: 'operations.index', icon: OperationsIcon, permissions: ['operations.view'] },
    { label: 'Backups', route: 'backups.index', icon: BackupIcon, permissions: ['backups.view', 'backups.manage'] },
    { label: 'Config', route: 'system-settings.edit', icon: SettingsIcon, permissions: ['settings.manage'] },
    { label: 'Superadmin', route: 'superadmin.index', icon: SuperadminIcon, permissions: ['superadmin.manage'] },
];

export default function AuthenticatedLayout({ header, children }) {
    const { auth, branding, notifications } = usePage().props;
    const user = auth.user;
    const permissionCodes = auth.permissions ?? [];
    const permissionSet = useMemo(() => new Set(permissionCodes), [permissionCodes]);
    const visibleNavigation = useMemo(() => {
        if (permissionCodes.length === 0) {
            return navigation;
        }

        return navigation.filter((item) => !item.permissions || item.permissions.some((permission) => permissionSet.has(permission)));
    }, [permissionCodes.length, permissionSet]);
    const [isCollapsed, setIsCollapsed] = useState(false);
    const [isMobileOpen, setIsMobileOpen] = useState(false);

    const cssVariables = useMemo(
        () => ({
            '--brand-primary': hexToRgb(branding.primaryColor),
            '--brand-secondary': hexToRgb(branding.secondaryColor),
            '--brand-accent': hexToRgb(branding.accentColor),
        }),
        [branding],
    );

    useEffect(() => {
        const theme = user?.theme_preference ?? localStorage.getItem('theme_preference') ?? 'light';
        localStorage.setItem('theme_preference', theme);
        applyTheme(theme);
    }, [user?.theme_preference]);

    return (
        <div className="app-shell min-h-screen bg-app-bg text-app-text" style={cssVariables}>
            <div className="app-ambient-bg pointer-events-none fixed inset-0 z-0" />

            <button
                type="button"
                className="glass-button fixed left-4 top-4 z-40 lg:hidden"
                onClick={() => setIsMobileOpen(true)}
            >
                <MenuIcon className="h-5 w-5" />
            </button>

            {isMobileOpen && (
                <button
                    type="button"
                    aria-label="Cerrar menu"
                    className="fixed inset-0 z-40 bg-stone-950/40 lg:hidden"
                    onClick={() => setIsMobileOpen(false)}
                />
            )}

            <aside
                className={`glass-panel fixed inset-y-4 left-4 z-50 flex min-h-0 flex-col overflow-hidden rounded-[2rem] transition-[width,transform] duration-300 will-change-[width,transform] lg:translate-x-0 ${
                    isCollapsed ? 'lg:w-20' : 'lg:w-72'
                } ${isMobileOpen ? 'w-72 translate-x-0' : '-translate-x-[115%] lg:translate-x-0'}`}
            >
                <div className={`relative flex h-20 shrink-0 items-center px-4 ${isCollapsed ? 'justify-center' : 'justify-between'}`}>
                    <Link href="/" className={`flex min-w-0 items-center gap-3 ${isCollapsed ? 'justify-center' : ''}`}>
                        {branding.logoUrl ? (
                            <img src={branding.logoUrl} alt="FIEA" className="h-11 w-11 rounded-2xl object-contain" />
                        ) : (
                            <ApplicationLogo className="h-11 w-11 shrink-0 fill-current text-brand-primary" />
                        )}
                        {!isCollapsed && (
                            <div className="min-w-0">
                                <p className="truncate text-base font-black">FIEA</p>
                                <p className="truncate text-xs font-semibold text-app-muted">Invoices & Budget</p>
                            </div>
                        )}
                    </Link>

                    <button
                        type="button"
                        className={`hidden rounded-2xl border border-white/40 bg-white/30 p-2 text-app-muted transition hover:text-app-text lg:block dark:border-white/10 dark:bg-white/10 ${
                            isCollapsed ? 'absolute -right-3 top-6 bg-white/70 shadow-lg dark:bg-stone-900/80' : ''
                        }`}
                        onClick={() => setIsCollapsed((value) => !value)}
                        aria-label={isCollapsed ? 'Expandir menu' : 'Colapsar menu'}
                    >
                        <ChevronIcon className={`h-4 w-4 transition ${isCollapsed ? 'rotate-180' : ''}`} />
                    </button>
                </div>

                <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 pr-2 [scrollbar-width:thin]">
                    {visibleNavigation.map((item) => {
                        const Icon = item.icon;
                        const active = route().current(item.route);

                        return (
                            <Link
                                key={item.label}
                                href={route(item.route)}
                                className={`group relative flex h-12 items-center gap-3 rounded-2xl px-3 text-sm font-bold transition ${
                                    active
                                        ? 'bg-brand-primary text-white shadow-lg shadow-stone-950/10'
                                        : 'text-app-muted hover:bg-white/35 hover:text-app-text dark:hover:bg-white/10'
                                }`}
                                onClick={() => setIsMobileOpen(false)}
                            >
                                <Icon className="h-5 w-5 shrink-0" />
                                {!isCollapsed && <span>{item.label}</span>}
                                {item.route === 'notifications.index' && notifications?.unread > 0 && (
                                    <span className={`${isCollapsed ? 'absolute right-2 top-2' : 'ml-auto'} rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-black text-white`}>
                                        {notifications.unread > 99 ? '99+' : notifications.unread}
                                    </span>
                                )}
                            </Link>
                        );
                    })}
                </nav>

                <div className={`shrink-0 border-t border-white/20 dark:border-white/10 ${isCollapsed ? 'space-y-3 p-2' : 'space-y-4 p-4'}`}>
                    <div className={isCollapsed ? 'flex justify-center' : 'glass-tile p-3'}>
                        <div className={`flex items-center ${isCollapsed ? 'justify-center' : 'justify-between'}`}>
                            {!isCollapsed && (
                                <div>
                                    <p className="text-xs font-bold uppercase text-app-muted">Apariencia</p>
                                    <p className="text-sm font-bold">Liquid glass</p>
                                </div>
                            )}
                            <ThemeToggle compact={isCollapsed} />
                        </div>
                    </div>

                    <div className={isCollapsed ? 'flex justify-center' : 'glass-tile p-3'}>
                        <div className={`flex items-center gap-3 ${isCollapsed ? 'justify-center' : ''}`}>
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-secondary text-sm font-black text-white">
                                {user.name?.charAt(0) ?? 'U'}
                            </div>
                            {!isCollapsed && (
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-black">{user.name}</p>
                                    <p className="truncate text-xs font-semibold text-app-muted">@{user.username}</p>
                                </div>
                            )}
                        </div>
                        {!isCollapsed && (
                            <div className="mt-3 grid grid-cols-2 gap-2">
                                <Link href={route('profile.edit')} className="glass-button px-3 py-2 text-xs">
                                    Perfil
                                </Link>
                                <Link href={route('logout')} method="post" as="button" className="glass-button px-3 py-2 text-xs">
                                    Salir
                                </Link>
                            </div>
                        )}
                    </div>
                </div>
            </aside>

            <div className={`relative z-10 transition-all duration-300 ${isCollapsed ? 'lg:pl-28' : 'lg:pl-80'}`}>
                {header && (
                    <header className="px-4 pb-4 pt-20 sm:px-6 lg:px-8 lg:pt-8">
                        {header}
                    </header>
                )}

                <main className="px-4 pb-10 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}

function IconBase({ className = '', children }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={className}>
            {children}
        </svg>
    );
}

function DashboardIcon(props) {
    return <IconBase {...props}><path d="M4 13h6v7H4z" /><path d="M14 4h6v16h-6z" /><path d="M4 4h6v5H4z" /></IconBase>;
}

function NotificationIcon(props) {
    return <IconBase {...props}><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10 21h4" /></IconBase>;
}

function CatalogIcon(props) {
    return <IconBase {...props}><path d="M5 5h14v4H5z" /><path d="M5 15h14v4H5z" /><path d="M8 9v6" /><path d="M16 9v6" /></IconBase>;
}

function ChapterIcon(props) {
    return <IconBase {...props}><path d="M5 6h14" /><path d="M7 10h10" /><path d="M5 14h14" /><path d="M8 18h8" /><path d="M9 6v12" /><path d="M15 6v12" /></IconBase>;
}

function ContactIcon(props) {
    return <IconBase {...props}><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" /><path d="M5 21a7 7 0 0 1 14 0" /><path d="M18 10h3" /><path d="M19.5 8.5v3" /></IconBase>;
}

function ProjectIcon(props) {
    return <IconBase {...props}><path d="M4 7h7l2 3h7v9H4z" /><path d="M4 7v12" /></IconBase>;
}

function TripIcon(props) {
    return <IconBase {...props}><path d="M4 18c5-8 11-12 16-12" /><path d="M8 18h.01" /><path d="M15 8h.01" /><path d="M19 6l-1 4 4-1" /></IconBase>;
}

function BudgetIcon(props) {
    return <IconBase {...props}><path d="M6 4h12v16H6z" /><path d="M9 8h6" /><path d="M9 12h6" /><path d="M9 16h2" /><path d="M14 16h1" /></IconBase>;
}

function InvoiceIcon(props) {
    return <IconBase {...props}><path d="M7 3h10v18l-2-1-2 1-2-1-2 1-2-1z" /><path d="M9 8h6" /><path d="M9 12h6" /><path d="M9 16h4" /></IconBase>;
}

function EmailIcon(props) {
    return <IconBase {...props}><path d="M4 6h16v12H4z" /><path d="m4 7 8 6 8-6" /></IconBase>;
}

function ExpenseIcon(props) {
    return <IconBase {...props}><path d="M12 3v18" /><path d="M17 7.5c0-1.7-2.2-3-5-3s-5 1.3-5 3 2.2 3 5 3 5 1.3 5 3-2.2 3-5 3-5-1.3-5-3" /></IconBase>;
}

function ReceiptIcon(props) {
    return <IconBase {...props}><path d="M7 3h10v18l-2-1-2 1-2-1-2 1-2-1z" /><path d="M9 8h6" /><path d="M9 12h6" /><path d="M9 16h3" /></IconBase>;
}

function AccountingIcon(props) {
    return <IconBase {...props}><path d="M5 19V5" /><path d="M5 19h14" /><path d="M9 15v-4" /><path d="M13 15V8" /><path d="M17 15v-2" /></IconBase>;
}

function ReportIcon(props) {
    return <IconBase {...props}><path d="M5 4h14v16H5z" /><path d="M8 15l2-3 3 2 3-5" /><path d="M8 18h8" /><path d="M8 7h8" /></IconBase>;
}

function AlertIcon(props) {
    return <IconBase {...props}><path d="M12 4 3 20h18z" /><path d="M12 9v5" /><path d="M12 17h.01" /></IconBase>;
}

function AuditIcon(props) {
    return <IconBase {...props}><path d="M12 3 5 6v6c0 4 3 7 7 9 4-2 7-5 7-9V6z" /><path d="m9 12 2 2 4-5" /></IconBase>;
}

function OperationsIcon(props) {
    return <IconBase {...props}><path d="M4 12h4l2-7 4 14 2-7h4" /><path d="M5 20h14" /></IconBase>;
}

function BackupIcon(props) {
    return <IconBase {...props}><path d="M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3Z" /><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6" /><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" /></IconBase>;
}

function SettingsIcon(props) {
    return <IconBase {...props}><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z" /><path d="M4 12h2" /><path d="M18 12h2" /><path d="m6.3 6.3 1.4 1.4" /><path d="m16.3 16.3 1.4 1.4" /><path d="M12 4v2" /><path d="M12 18v2" /><path d="m17.7 6.3-1.4 1.4" /><path d="m7.7 16.3-1.4 1.4" /></IconBase>;
}

function SuperadminIcon(props) {
    return <IconBase {...props}><path d="M12 3 4 7v6c0 4 3.4 6.8 8 8 4.6-1.2 8-4 8-8V7z" /><path d="M9 12h6" /><path d="M12 9v6" /></IconBase>;
}

function MenuIcon(props) {
    return <IconBase {...props}><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></IconBase>;
}

function ChevronIcon(props) {
    return <IconBase {...props}><path d="m15 6-6 6 6 6" /></IconBase>;
}
