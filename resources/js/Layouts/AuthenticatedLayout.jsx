import ApplicationLogo from '@/Components/ApplicationLogo';
import { readPreference, writePreference } from '@/Utils/preferences';
import ThemeToggle from '@/Components/ThemeToggle';
import WorkflowGuideCard from '@/Components/WorkflowGuideCard';
import HelpTooltip from '@/Components/HelpTooltip';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { applyTheme, hexToRgb } from '@/Utils/theme';
import GlassScene, { MacroGlass } from '@/Components/Glass/GlassScene';
import { moduleFlow } from '@/Content/moduleFlows';

const navigation = [
    { label: 'Panel', route: 'dashboard', icon: DashboardIcon, permissions: ['dashboard.view'] },
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
    { label: 'Control anual', href: '/accounting/control', icon: AccountingIcon, permissions: ['accounting.view', 'accounting.manage'] },
    { label: 'Reportes', route: 'reports.index', icon: ReportIcon, permissions: ['reports.view'] },
    { label: 'Alertas', route: 'alerts.index', icon: AlertIcon, permissions: ['alerts.view'] },
    { label: 'Auditoria', route: 'audit-logs.index', icon: AuditIcon, permissions: ['audit_logs.view'] },
    { label: 'Estado', route: 'operations.index', icon: OperationsIcon, permissions: ['operations.view'] },
    { label: 'Backups', route: 'backups.index', icon: BackupIcon, permissions: ['backups.view', 'backups.manage'] },
    { label: 'Config', route: 'system-settings.edit', icon: SettingsIcon, permissions: ['settings.manage'] },
    { label: 'Superadmin', route: 'superadmin.index', icon: SuperadminIcon, permissions: ['superadmin.manage'] },
    { label: 'Estudio Liquid Glass', route: 'liquid-glass.edit', icon: SettingsIcon, superadminOnly: true },
];

export default function AuthenticatedLayout({ header, children, brandingPreview }) {
    const { auth, branding, notifications, liquidGlass } = usePage().props;
    const user = auth.user;
    const permissionCodes = auth.permissions ?? [];
    const permissionSet = useMemo(() => new Set(permissionCodes), [permissionCodes]);
    const visibleNavigation = useMemo(
        () => navigation.filter((item) => (!item.superadminOnly || user.roles?.some((role) => role.code === 'superadmin')) && (!item.permissions || item.permissions.some((permission) => permissionSet.has(permission)))),
        [permissionSet, user.roles],
    );
    const [isCollapsed, setIsCollapsed] = useState(false);
    const [isMobileOpen, setIsMobileOpen] = useState(false);
    const menuRef = useRef(null);

    useEffect(() => {
        if (!isMobileOpen) return undefined;
        const previous = document.activeElement;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        menuRef.current?.querySelector('button')?.focus();
        const trap = (event) => {
            if (event.key === 'Escape') { setIsMobileOpen(false); return; }
            if (event.key !== 'Tab') return;
            const controls = Array.from(menuRef.current?.querySelectorAll('button,a[href],input') ?? []).filter((element) => element.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        };
        document.addEventListener('keydown', trap);
        return () => { document.body.style.overflow = overflow; document.removeEventListener('keydown', trap); previous?.focus(); };
    }, [isMobileOpen]);
    const cssVariables = useMemo(
        () => ({
            '--brand-primary': hexToRgb(brandingPreview?.primaryColor ?? branding.primaryColor),
            '--brand-secondary': hexToRgb(brandingPreview?.secondaryColor ?? branding.secondaryColor),
            '--brand-accent': hexToRgb(brandingPreview?.accentColor ?? branding.accentColor),
        }),
        [branding, brandingPreview],
    );

    useEffect(() => {
        const theme = user?.theme_preference ?? readPreference('theme_preference', 'light');
        writePreference('theme_preference', theme);
        applyTheme(theme);
    }, [user?.theme_preference]);

    useEffect(() => {
        const media = window.matchMedia('(min-width:768px)');
        const close = () => { if (media.matches) setIsMobileOpen(false); };
        media.addEventListener('change', close);
        return () => media.removeEventListener('change', close);
    }, []);

    const renderNavigation = (collapsed, closeMenu) => <>
        <div className={`relative flex h-20 shrink-0 items-center px-4 ${collapsed ? 'justify-center' : 'justify-between'}`}>
            <Link href="/" onClick={closeMenu} aria-label="Inicio de FIEA" className="flex min-w-0 items-center gap-3">
                {branding.logoUrl ? <img crossOrigin="anonymous" src={branding.logoUrl} alt="" className="h-11 w-11 rounded-2xl object-contain" />
                    : <ApplicationLogo className="h-11 w-11 shrink-0 fill-current text-brand-primary" />}
                {!collapsed && <div className="min-w-0"><p className="truncate text-base font-black">FIEA</p><p className="truncate text-xs font-semibold text-app-muted">Invoices & Budget</p></div>}
            </Link>
            <button type="button" className="hidden rounded-2xl border border-white/40 bg-white/30 p-2 text-app-muted md:block"
                onClick={() => setIsCollapsed((value) => !value)}
                aria-label={collapsed ? 'Expandir menú' : 'Colapsar menú'}>
                <ChevronIcon className={`h-4 w-4 ${collapsed ? 'rotate-180' : ''}`} />
            </button>
        </div>
        <nav aria-label="Navegación principal" className="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 pr-2">
            {visibleNavigation.map((item) => {
                const Icon = item.icon;
                const active = item.route ? route().current(item.route) : window.location.pathname === item.href;
                return <Link key={item.label} href={item.href ?? route(item.route)} onClick={closeMenu}
                    aria-label={item.label} aria-current={active ? 'page' : undefined}
                    className={`lg-micro-glass dock-link group relative flex h-12 items-center gap-3 rounded-2xl px-3 text-sm font-bold ${active ? 'dock-link-active text-white' : 'text-app-muted hover:text-app-text'}`}>
                    <Icon className="h-5 w-5 shrink-0" />
                    {!collapsed && <span>{item.label}</span>}
                    {item.route === 'notifications.index' && notifications?.unread > 0 &&
                        <span className={`${collapsed ? 'absolute right-2 top-2' : 'ml-auto'} rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-black text-white`}>
                            {notifications.unread > 99 ? '99+' : notifications.unread}
                        </span>}
                </Link>;
            })}
        </nav>
        <div className={`shrink-0 border-t border-white/20 ${collapsed ? 'space-y-2 p-2' : 'space-y-3 p-4'}`}>
            <div className={collapsed ? 'flex justify-center' : 'glass-tile flex items-center justify-between p-3'}>
                {!collapsed && <span className="text-sm font-bold">Apariencia</span>}
                <ThemeToggle compact={collapsed} />
            </div>
            <div className={collapsed ? 'space-y-2' : 'glass-tile space-y-3 p-3'}>
                {!collapsed && <p className="truncate text-sm font-bold">{user.name} <span className="font-normal">@{user.username}</span></p>}
                <div className={`flex gap-2 ${collapsed ? 'flex-col' : ''}`}>
                    <Link href={route('profile.edit')} onClick={closeMenu} aria-label="Perfil" className="glass-button flex-1 px-2">{collapsed ? 'P' : 'Perfil'}</Link>
                    <Link href={route('logout')} method="post" as="button" onClick={closeMenu} aria-label="Salir" className="glass-button flex-1 px-2">{collapsed ? 'S' : 'Salir'}</Link>
                </div>
            </div>
        </div>
    </>;

    const currentModule = window.location.pathname.split('/').filter(Boolean)[0] ?? 'dashboard';
    const currentLabel = navigation.find((item) => item.href === window.location.pathname || (item.route && route().current(item.route)))?.label ?? (currentModule === 'perfil' ? 'Perfil' : 'Módulo');
    const flow = moduleFlow(window.location.pathname, currentLabel);
    const guideSteps = flow.steps.map((step, index) => ({ id: `${currentModule}-${index}`, text: step }));

    return <GlassScene profiles={liquidGlass} className={`app-shell authenticated-scene text-app-text ${isMobileOpen ? 'mobile-menu-open' : ''} ${isCollapsed ? 'sidebar-collapsed' : 'sidebar-expanded'}`} style={cssVariables}>
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] glass-button">Saltar al contenido</a>
        <MacroGlass as="aside" id="mobile-navigation" profile="sidebar" className="app-navigation optical-dock" config={{ floating: false, button: false }} role={isMobileOpen ? 'dialog' : undefined} aria-modal={isMobileOpen || undefined} aria-label="Navegación de FIEA">
            <div ref={menuRef} className="dock-reading-layer">
                <button type="button" className="glass-button mobile-close" onClick={() => setIsMobileOpen(false)}>Cerrar menú <span aria-hidden="true">×</span></button>
                {renderNavigation(isMobileOpen ? false : isCollapsed, () => setIsMobileOpen(false))}
            </div>
        </MacroGlass>
        <button type="button" className="app-navigation glass-button fixed left-4 top-4 z-40 md:hidden"
            aria-label="Abrir navegación" aria-expanded={isMobileOpen} aria-controls="mobile-navigation"
            onClick={() => setIsMobileOpen(true)}><MenuIcon className="h-5 w-5" /></button>
        <MacroGlass as="header" className="scene-header scene-push" config={{ floating: false, button: false }}><div className="flex items-center justify-between gap-3">{header ?? <h1>{currentLabel}</h1>}<HelpTooltip label={`Ayuda de ${currentLabel}`} text={`${flow.purpose} ${flow.result}`} /></div></MacroGlass>
        <MacroGlass className="scene-guide scene-push" config={{ floating: false, button: false }}>
            <WorkflowGuideCard bare guideKey={window.location.pathname} userId={user.id} title={flow.purpose} summary={flow.result} result={flow.result} steps={guideSteps} />
        </MacroGlass>
            <main id="main-workspace" tabIndex={-1} className="app-content scene-push" inert={isMobileOpen ? '' : undefined}>
                <span id="main-content" />
                {children}
            </main>
    </GlassScene>;
}
function IconBase({ className = '', children }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" className={className}>
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
