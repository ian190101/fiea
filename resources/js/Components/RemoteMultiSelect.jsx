import { useEffect, useId, useRef, useState } from 'react';

export default function RemoteMultiSelect({ label, url, value, selectedOptions = [], onChange, disabled = false }) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState([]);
    const [next, setNext] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const controller = useRef(null);
    const known = useRef(new Map(selectedOptions.map((item) => [Number(item.id), item])));
    const remember = (items) => { items.forEach((item) => known.current.set(Number(item.id), item)); return items; };
    useEffect(() => {
        const abort = new AbortController();
        controller.current = abort;
        setLoading(true); setError(''); setOptions([]); setNext(null);
        const timer = setTimeout(() => {
            window.axios.get(url, { params: { q: query }, signal: abort.signal, headers: { Accept: 'application/json' } })
                .then(({ data }) => { if (!abort.signal.aborted) { setOptions(remember(data.data)); setNext(data.next_page_url); } })
                .catch(() => { if (!abort.signal.aborted) setError('No se pudieron cargar las opciones. Cambia la búsqueda para reintentar.'); })
                .finally(() => { if (!abort.signal.aborted) setLoading(false); });
        }, 200);
        return () => { clearTimeout(timer); abort.abort(); };
    }, [query, url]);
    const more = async () => {
        const abort = controller.current;
        if (!next || loading || !abort) return;
        setLoading(true); setError('');
        try {
            const { data } = await window.axios.get(next, { signal: abort.signal, headers: { Accept: 'application/json' } });
            if (!abort.signal.aborted) {
                setOptions((old) => Array.from(new Map([...old, ...remember(data.data)].map((item) => [item.id, item])).values()));
                setNext(data.next_page_url);
            }
        } catch { if (!abort.signal.aborted) setError('No se pudieron cargar más opciones. Puedes reintentar.'); }
        finally { if (!abort.signal.aborted) setLoading(false); }
    };
    const toggle = (item) => onChange(value.includes(Number(item.id)) ? value.filter((v) => v !== Number(item.id)) : [...value, Number(item.id)]);
    return <fieldset disabled={disabled} className="space-y-2" aria-busy={loading}>
        <legend className="font-semibold">{label}</legend>
        <label htmlFor={id} className="block text-sm">Buscar {label.toLowerCase()}</label>
        <input id={id} type="search" value={query} maxLength={120} className="ios-field w-full" onChange={(event) => setQuery(event.target.value)} />
        <ul aria-label={`${label} seleccionados`} className="flex flex-wrap gap-2">
            {value.map((key) => <li key={key}><button type="button" className="glass-button px-3" onClick={() => onChange(value.filter((v) => v !== key))}
                aria-label={`Retirar ${known.current.get(key)?.name ?? key}`}>{known.current.get(key)?.name ?? key} ×</button></li>)}
        </ul>
        <div className="max-h-48 space-y-2 overflow-auto rounded-xl border p-3">
            {options.map((item) => <label key={item.id} className="flex items-center gap-3 py-1">
                <input type="checkbox" checked={value.includes(Number(item.id))} onChange={() => toggle(item)} />{item.name}
            </label>)}
            {!options.length && !loading && <p>Sin resultados para esta búsqueda.</p>}
        </div>
        {loading && <p role="status">Cargando opciones…</p>}
        {error && <p role="alert">{error}</p>}
        {next && <button type="button" className="glass-button px-4" disabled={loading} onClick={more}>Cargar más</button>}
    </fieldset>;
}
