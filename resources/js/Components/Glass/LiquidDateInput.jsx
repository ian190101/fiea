import { forwardRef, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const isoDate = (year, month, day) => `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

export default forwardRef(function LiquidDateInput({ value = '', onChange, className = '', min, max, disabled, ...props }, ref) {
    const control = useRef(null), popup = useRef(null);
    const popupId = useId();
    const initial = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date();
    const [month, setMonth] = useState(() => new Date(initial.getFullYear(), initial.getMonth(), 1));
    const [open, setOpen] = useState(false), [position, setPosition] = useState({});
    const year = month.getFullYear(), monthIndex = month.getMonth();
    const leading = (new Date(year, monthIndex, 1).getDay() + 6) % 7;
    const days = new Date(year, monthIndex + 1, 0).getDate();
    const send = (next) => { onChange?.({ target: { value: next, name: props.name, id: props.id }, currentTarget: { value: next } }); };
    useLayoutEffect(() => {
        if (!open) return undefined;
        const locate = () => { const box = control.current.getBoundingClientRect(); setPosition({ left: Math.max(12, Math.min(box.left, innerWidth - 316)), top: Math.max(12, Math.min(box.bottom + 8, innerHeight - 375)) }); };
        locate(); window.addEventListener('scroll', locate, true); window.addEventListener('resize', locate);
        return () => { window.removeEventListener('scroll', locate, true); window.removeEventListener('resize', locate); };
    }, [open]);
    useEffect(() => {
        if (!open) return undefined;
        const outside = (event) => { if (!control.current?.contains(event.target) && !popup.current?.contains(event.target)) setOpen(false); };
        const escape = (event) => { if (event.key === 'Escape') { setOpen(false); control.current?.querySelector('button')?.focus(); } };
        document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
    }, [open]);
    return <span className="liquid-date-control" ref={control}>
        <input {...props} ref={ref} value={value ?? ''} disabled={disabled} onChange={(event) => send(event.target.value)} type="text" inputMode="numeric" placeholder="AAAA-MM-DD" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" className={`lg-micro-glass ${className}`} />
        <button type="button" className="liquid-date-trigger glass-button" aria-label="Abrir calendario" aria-expanded={open} aria-controls={popupId} disabled={disabled} onClick={() => setOpen(!open)}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M5 5h14v15H5zM8 2v6m8-6v6M5 10h14M9 14h2m2 3h2" /></svg></button>
        {open && createPortal(<div ref={popup} id={popupId} role="dialog" aria-label="Seleccionar fecha" className="liquid-calendar lg-micro-glass" style={position}>
            <div className="flex items-center justify-between gap-2"><button type="button" className="glass-button" aria-label="Mes anterior" onClick={() => setMonth(new Date(year, monthIndex - 1, 1))}>‹</button><strong aria-live="polite">{new Intl.DateTimeFormat('es', { month: 'long', year: 'numeric' }).format(month)}</strong><button type="button" className="glass-button" aria-label="Mes siguiente" onClick={() => setMonth(new Date(year, monthIndex + 1, 1))}>›</button></div>
            <div className="calendar-grid mt-3">{['L', 'M', 'X', 'J', 'V', 'S', 'D'].map((day) => <span key={day} className="calendar-weekday">{day}</span>)}
                {Array.from({ length: leading }, (_, index) => <span key={`empty-${index}`} />)}
                {Array.from({ length: days }, (_, index) => { const day = index + 1, iso = isoDate(year, monthIndex, day); return <button key={day} type="button" className={`glass-button calendar-day ${value === iso ? 'is-selected' : ''}`} aria-label={iso} aria-pressed={value === iso} disabled={(min && iso < min) || (max && iso > max)} onClick={() => { send(iso); setOpen(false); control.current?.querySelector('input')?.focus(); }}>{day}</button>; })}
            </div>
            <div className="mt-3 flex justify-between"><button type="button" className="glass-button" onClick={() => { send(''); setOpen(false); }}>Borrar</button><button type="button" className="glass-button" onClick={() => { const today = new Date(); setMonth(new Date(today.getFullYear(), today.getMonth(), 1)); }}>Hoy</button><button type="button" className="glass-button" onClick={() => setOpen(false)}>Cerrar</button></div>
        </div>, control.current?.closest('[role="dialog"]') ?? document.body)}
    </span>;
});
