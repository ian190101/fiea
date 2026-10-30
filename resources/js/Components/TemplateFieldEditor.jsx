import { useRef } from 'react';
import InputLabel from './InputLabel';
import InputError from './InputError';
import { readableTemplate, storedTemplate, templateFieldLabels } from '@/Utils/templateFields';

export default function TemplateFieldEditor({ id, label, value, onChange, error, multiline = false, tokens }) {
    const input = useRef(null);
    const text = readableTemplate(value);
    const Tag = multiline ? 'textarea' : 'input';
    const insert = (token) => {
        const element = input.current, marker = readableTemplate(token);
        const start = element.selectionStart ?? text.length, end = element.selectionEnd ?? start;
        onChange(storedTemplate(text.slice(0, start) + marker + text.slice(end)));
        requestAnimationFrame(() => { element.focus(); element.setSelectionRange(start + marker.length, start + marker.length); });
    };
    return <div className="template-field-editor">
        <InputLabel htmlFor={id} value={label} help="Escribe el texto y usa los botones para insertar datos automáticos. Los campos entre ‹ › se completan al preparar el correo." />
        <Tag ref={input} id={id} type={multiline ? undefined : 'text'} rows={multiline ? 8 : undefined} value={text} onChange={(event) => onChange(storedTemplate(event.target.value))} aria-invalid={Boolean(error)} aria-describedby={`${id}-hint${error ? ` ${id}-error` : ''}`} className="ios-field mt-1 block w-full" />
        <InputError id={`${id}-error`} message={error} className="mt-2" />
        <p id={`${id}-hint`} className="mt-2 text-xs text-app-muted">Insertar un dato que se completará automáticamente:</p>
        <div className="mt-2 flex flex-wrap gap-2">{Object.keys(tokens ?? templateFieldLabels).map((token) => <button key={token} type="button" className="glass-button template-field-chip" onPointerDown={(event) => event.preventDefault()} onClick={() => insert(token)}>{templateFieldLabels[token] ?? tokens[token]}</button>)}</div>
    </div>;
}
