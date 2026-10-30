import { Children, forwardRef, isValidElement, useEffect, useId, useImperativeHandle, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import RemoteOptionSelect from '@/Components/RemoteOptionSelect';

const remoteFields = { country_id:'countries',community_id:'communities',project_id:'projects',team_id:'teams',
    chapter_id:'chapters',team_chapter_id:'chapters',chapter_type_id:'chapter-types',university_id:'universities',
    assigned_technician_id:'technicians',trip_phase_id:'trip-phases',estimated_expense_id:'estimated-expenses',
    actual_expense_id:'actual-expenses',budget_line_id:'budget-lines','audit-user':'audit-users' };

export default forwardRef(function Select(props,ref) {
    const {remoteKind,...rest}=props;
    const kind=remoteKind ?? remoteFields[props.id];
    return kind ? <RemoteOptionSelect {...rest} kind={kind}/> : <LocalLiquidSelect {...rest} ref={ref}/>;
});

function flattenOptions(children, group = '') {
    return Children.toArray(children).flatMap((child) => {
        if (!isValidElement(child)) return [];
        if (child.type === 'option') return [{ value: String(child.props.value ?? child.props.children ?? ''), label: child.props.children, disabled: child.props.disabled, group }];
        return flattenOptions(child.props.children, child.props.label ?? group);
    });
}

// Conserva el contrato de los formularios existentes y reemplaza el desplegable del sistema operativo.
const LocalLiquidSelect = forwardRef(function LiquidSelect({ children, value, defaultValue, onChange, className = '', id, name, disabled, required, ...props }, ref) {
    const generated = useId();
    const listId = `${generated}-list`;
    const trigger = useRef(null), panel = useRef(null);
    const options = flattenOptions(children);
    const [local, setLocal] = useState(defaultValue ?? options[0]?.value ?? '');
    const selected = String(value ?? local);
    const [open, setOpen] = useState(false), [active, setActive] = useState(0);
    const [position, setPosition] = useState({});
    useImperativeHandle(ref, () => ({ focus: () => trigger.current?.focus() }));
    const choose = (option) => {
        if (!option || option.disabled) return;
        setLocal(option.value); onChange?.({ target: { value: option.value, name, id, type: 'select-one' }, currentTarget: { value: option.value, name } });
        setOpen(false); trigger.current?.focus();
    };
    useLayoutEffect(() => {
        if (!open) return undefined;
        const locate = () => {
            const box = trigger.current.getBoundingClientRect();
            const height = Math.min(300, Math.max(140, options.length * 44 + 16));
            const below = innerHeight - box.bottom - 12;
            setPosition({ left: Math.max(12, Math.min(box.left, innerWidth - Math.max(180, box.width) - 12)), width: Math.max(180, box.width), maxHeight: Math.min(height, Math.max(below, box.top - 12)), top: below >= Math.min(height, 180) ? box.bottom + 6 : undefined, bottom: below >= Math.min(height, 180) ? undefined : innerHeight - box.top + 6 });
        };
        locate(); window.addEventListener('resize', locate); window.addEventListener('scroll', locate, true);
        return () => { window.removeEventListener('resize', locate); window.removeEventListener('scroll', locate, true); };
    }, [open, options.length]);
    useEffect(() => {
        if (!open) return undefined;
        const outside = (event) => { if (!trigger.current?.contains(event.target) && !panel.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('pointerdown', outside);
        return () => document.removeEventListener('pointerdown', outside);
    }, [open]);
    const keyDown = (event) => {
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault(); setOpen(true);
            const direction = event.key === 'ArrowUp' ? -1 : 1;
            let next = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (active + direction + options.length) % options.length;
            for (let i = 0; options[next]?.disabled && i < options.length; i++) next = (next + direction + options.length) % options.length;
            setActive(next); requestAnimationFrame(() => panel.current?.querySelector(`[data-option-index="${next}"]`)?.scrollIntoView({ block: 'nearest' }));
        } else if (event.key === 'Escape' || event.key === 'Tab') setOpen(false);
        else if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); if (open) choose(options[active]); else setOpen(true); }
        else if (event.key.length === 1) { const index = options.findIndex((option) => !option.disabled && String(option.label).toLocaleLowerCase().startsWith(event.key.toLocaleLowerCase())); if (index >= 0) { setActive(index); setOpen(true); } }
    };
    return <>
        <button {...props} type="button" id={id} ref={trigger} role="combobox" aria-haspopup="listbox" aria-controls={listId} aria-expanded={open} aria-required={required || undefined} aria-activedescendant={open ? `${listId}-${active}` : undefined} disabled={disabled} className={`liquid-select-trigger lg-micro-glass ${className}`} onKeyDown={keyDown} onClick={() => { setActive(Math.max(0, options.findIndex((option) => option.value === selected))); setOpen(!open); }}>
            <span className="truncate">{options.find((option) => option.value === selected)?.label ?? 'Selecciona una opción'}</span><span aria-hidden="true">⌄</span>
        </button>
        {name && <input type="hidden" name={name} value={selected} />}
        {open && createPortal(<div id={listId} role="listbox" ref={panel} className="liquid-select-options lg-micro-glass" style={position} aria-label={props['aria-label'] ?? 'Opciones'}>
            {options.map((option, index) => <div key={`${option.value}-${index}`} id={`${listId}-${index}`} role="option" aria-selected={selected === option.value} aria-disabled={option.disabled || undefined} data-option-index={index} className={`liquid-select-option ${active === index ? 'is-active' : ''}`} onPointerMove={() => setActive(index)} onPointerDown={(event) => event.preventDefault()} onClick={() => choose(option)}>{option.group && <small>{option.group} · </small>}{option.label}{selected === option.value && <span aria-hidden="true">✓</span>}</div>)}
        </div>, trigger.current?.closest('[role="dialog"]') ?? document.body)}
    </>;
});
