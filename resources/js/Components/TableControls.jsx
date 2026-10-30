import LiquidSelect from '@/Components/Glass/LiquidSelect';
import IconButton from '@/Components/IconButton';
import { useId } from 'react';

export default function TableControls({
    query,
    onQueryChange,
    page,
    totalPages,
    pageSize,
    onPageSizeChange,
    onPageChange,
    total,
    filtered,
    placeholder = 'Buscar',
}) {
    const searchId = useId();
    const pageSizeId = useId();
    return (
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="relative w-full md:max-w-xs">
                <label htmlFor={searchId} className="sr-only">{placeholder}</label>
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-app-muted">
                    <svg aria-hidden="true" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                        <path d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z" />
                    </svg>
                </span>
                <input
                    id={searchId}
                    type="search"
                    value={query}
                    onChange={(event) => onQueryChange(event.target.value)}
                    placeholder={placeholder}
                    className="ios-field w-full pl-10"
                />
            </div>

            <nav aria-label="Paginación de resultados" className="flex flex-wrap items-center gap-2 text-sm font-bold text-app-muted">
                <span aria-live="polite">{filtered} de {total}</span>
                <label htmlFor={pageSizeId} className="sr-only">Resultados por página</label>
                <LiquidSelect
                    id={pageSizeId}
                    value={pageSize}
                    onChange={(event) => onPageSizeChange(event.target.value)}
                    className="ios-field py-2 text-sm"
                >
                    {[10, 25, 50].map((size) => (
                        <option key={size} value={size}>{size}</option>
                    ))}
                </LiquidSelect>
                <IconButton
                    icon="chevronLeft"
                    label="Página anterior"
                    type="button"
                    disabled={page <= 1}
                    onClick={() => onPageChange(page - 1)}
                    className="disabled:cursor-not-allowed disabled:opacity-40"
                />
                <span>{page}/{totalPages}</span>
                <IconButton
                    icon="chevronRight"
                    label="Página siguiente"
                    type="button"
                    disabled={page >= totalPages}
                    onClick={() => onPageChange(page + 1)}
                    className="disabled:cursor-not-allowed disabled:opacity-40"
                />
            </nav>
        </div>
    );
}
