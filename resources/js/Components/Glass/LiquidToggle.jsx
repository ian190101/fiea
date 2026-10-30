import { useId } from 'react';

export default function LiquidToggle({ checked, onChange, label, ariaLabel, id, name, disabled = false, className = '' }) {
    const generated = useId();
    return <label className={`liquid-toggle-label ${className}`} htmlFor={id ?? generated}>
        <input id={id ?? generated} name={name} aria-label={ariaLabel} type="checkbox" role="switch" className="liquid-toggle-input" checked={checked} onChange={onChange} disabled={disabled} />
        <span className="liquid-toggle-track lg-micro-glass" aria-hidden="true"><span className="liquid-toggle-thumb" /></span>
        {label && <span>{label}</span>}
    </label>;
}
