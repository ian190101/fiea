import { Children, useEffect, useRef, useState } from 'react';

const labels={countries:'países',communities:'comunidades',projects:'proyectos',teams:'equipos',chapters:'capítulos',
    'chapter-types':'tipos de capítulo',universities:'universidades',technicians:'técnicos','trip-phases':'viajes',
    'estimated-expenses':'gastos estimados','actual-expenses':'gastos reales','budget-lines':'líneas Final','audit-users':'usuarios con eventos'};

export default function RemoteOptionSelect({ kind, children, value, onChange, id, name, disabled, required, className, remoteParams = {}, ...props }) {
    const [query,setQuery]=useState('');
    const [rows,setRows]=useState([]);
    const [next,setNext]=useState(null);
    const [loading,setLoading]=useState(false);
    const [error,setError]=useState('');
    const [selectedRow,setSelectedRow]=useState(null);
    const controller=useRef(null);
    const paramsKey=JSON.stringify(remoteParams);
    useEffect(()=>{
        if (!value) {setSelectedRow(null);return undefined;}
        const abort=new AbortController();
        window.axios.get(route('options.lookup',kind),{params:{...remoteParams,selected_id:value},signal:abort.signal})
            .then(({data})=>{if(!abort.signal.aborted){setSelectedRow(data.data[0]??null);
                window.dispatchEvent(new CustomEvent('fiea-options-loaded',{detail:{kind,rows:data.data}}));}})
            .catch(()=>{});
        return ()=>abort.abort();
    },[kind,value,paramsKey]);
    useEffect(()=>{
        const abort=new AbortController();
        controller.current=abort;
        const timer=setTimeout(async()=>{
            setLoading(true); setError('');
            try {
                const {data}=await window.axios.get(route('options.lookup',kind),{params:{...remoteParams,q:query},signal:abort.signal});
                if (abort.signal.aborted) return;
                window.dispatchEvent(new CustomEvent('fiea-options-loaded',{detail:{kind,rows:data.data}}));
                setRows(data.data); setNext(data.next_page_url);
            } catch { if (!abort.signal.aborted) setError('No se pudieron cargar las opciones. Cambia la búsqueda para reintentar.'); }
            finally { if (!abort.signal.aborted) setLoading(false); }
        },200);
        return ()=>{clearTimeout(timer);abort.abort();};
    },[kind,query,paramsKey]);
    const more=async()=>{
        if (!next || loading) return;
        const abort=controller.current;
        setLoading(true);setError('');
        try {
            const {data}=await window.axios.get(next,{signal:abort.signal});
            if (abort.signal.aborted) return;
            window.dispatchEvent(new CustomEvent('fiea-options-loaded',{detail:{kind,rows:data.data}}));
            setRows(old=>Array.from(new Map([...old,...data.data].map(row=>[row.id,row])).values()));setNext(data.next_page_url);
        } catch {if(!abort.signal.aborted)setError('No se pudieron cargar más opciones.');} finally {if(!abort.signal.aborted)setLoading(false);}
    };
    return <div className="space-y-2" aria-busy={loading}>
        <input aria-label={`Buscar ${labels[kind] ?? 'opciones'}`} type="search" className="ios-field w-full" maxLength={120}
            value={query} onChange={event=>setQuery(event.target.value)} disabled={disabled}/>
        <select {...props} id={id} name={name} value={value ?? ''} onChange={onChange} disabled={disabled} required={required} className={className}>
            {children}
            {selectedRow && !rows.some(row=>String(row.id)===String(selectedRow.id))
                && !Children.toArray(children).some(child=>String(child?.props?.value)===String(selectedRow.id))
                && <option value={selectedRow.id}>{selectedRow.name}</option>}
            {rows.filter(row=>!Children.toArray(children).some(child=>String(child?.props?.value)===String(row.id)))
                .map(row=><option key={`remote-${row.id}`} value={row.id}>{row.name}</option>)}
        </select>
        {loading && <p role="status">Cargando opciones…</p>}
        {error && <p role="alert">{error}</p>}
        {next && <button type="button" disabled={loading || disabled} onClick={more} className="glass-button px-3">Cargar más opciones</button>}
    </div>;
}
