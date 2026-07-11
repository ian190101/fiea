import { useMemo, useState } from 'react';

export function useLocalTable(rows, searchableText, initialPageSize = 10) {
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(initialPageSize);

    const filteredRows = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        if (!normalizedQuery) {
            return rows;
        }

        return rows.filter((row) => searchableText(row).toLowerCase().includes(normalizedQuery));
    }, [query, rows, searchableText]);

    const totalPages = Math.max(Math.ceil(filteredRows.length / pageSize), 1);
    const safePage = Math.min(page, totalPages);
    const paginatedRows = useMemo(() => {
        const start = (safePage - 1) * pageSize;

        return filteredRows.slice(start, start + pageSize);
    }, [filteredRows, pageSize, safePage]);

    const updateQuery = (value) => {
        setQuery(value);
        setPage(1);
    };

    const updatePageSize = (value) => {
        setPageSize(Number(value));
        setPage(1);
    };

    return {
        filteredRows,
        page: safePage,
        pageSize,
        paginatedRows,
        query,
        setPage,
        totalPages,
        updatePageSize,
        updateQuery,
    };
}
