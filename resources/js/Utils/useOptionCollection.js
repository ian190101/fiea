import { useEffect, useState } from 'react';

export function useOptionCollection(initial, kind) {
    const [rows, setRows] = useState(initial);
    const initialKey=JSON.stringify(initial);
    useEffect(() => { setRows(initial); }, [initialKey]);
    useEffect(() => {
        const loaded = event => {
            if (event.detail.kind === kind) setRows(old => Array.from(new Map([...old, ...event.detail.rows].map(row => [Number(row.id), row])).values()));
        };
        window.addEventListener('fiea-options-loaded', loaded);
        return () => window.removeEventListener('fiea-options-loaded', loaded);
    }, [kind]);
    return rows;
}
