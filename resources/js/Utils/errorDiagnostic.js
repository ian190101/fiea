const sensitive = /password|token|secret|authorization|cookie|credential/i;

export function serializeDiagnostic(value) {
    const seen = new WeakSet();
    const sanitize = (item, key = '') => {
        if (sensitive.test(key)) return '[PROTEGIDO]';
        // Axios conserva el formulario como una cadena JSON: no debe eludir la redacción recursiva.
        if (key === 'data' && typeof item === 'string') {
            try { return sanitize(JSON.parse(item)); } catch { return '[CUERPO DE SOLICITUD OMITIDO]'; }
        }
        if (key === 'request') return '[SOLICITUD HTTP OMITIDA]';
        if (typeof item === 'string') {
            if (/^[\s]*[\[{]/.test(item)) {
                try { return sanitize(JSON.parse(item)); } catch { /* Una traza puede comenzar con un corchete. */ }
            }
            return item;
        }
        if (!item || typeof item !== 'object') return item;
        if (seen.has(item)) return '[REFERENCIA CIRCULAR]';
        seen.add(item);
        const source = item instanceof Error ? { message: item.message, stack: item.stack, ...item } : item;
        if (Array.isArray(source)) return source.map((entry) => sanitize(entry));
        return Object.fromEntries(Object.entries(source).filter(([, entry]) => typeof entry !== 'function').map(([name, entry]) => [name, sanitize(entry, name)]));
    };
    const sanitized = sanitize(value);
    return typeof sanitized === 'string' ? sanitized : JSON.stringify(sanitized, null, 2);
}
