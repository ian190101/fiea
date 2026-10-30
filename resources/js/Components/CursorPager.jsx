import { Link } from '@inertiajs/react';

export default function CursorPager({ page, label = 'resultados', firstPageUrl }) {
    if (!page || Array.isArray(page) || (!page.prev_page_url && !page.next_page_url && !firstPageUrl)) return null;
    return <nav aria-label={`Paginación de ${label}`} className="glass-pagination mt-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-app-muted">{page.data?.length ?? 0} {label} en esta página</p>
        <div className="flex flex-wrap items-center gap-2">
        {firstPageUrl && <Link className="glass-button" href={firstPageUrl} preserveScroll>Volver a la primera página</Link>}
        {page.prev_page_url && <Link className="glass-button" href={page.prev_page_url} preserveScroll>Página anterior</Link>}
        {page.next_page_url && <Link className="glass-button" href={page.next_page_url} preserveScroll>Página siguiente</Link>}
        </div>
    </nav>;
}
