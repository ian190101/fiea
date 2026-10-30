import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import FinancialActionSheet from '@/Components/FinancialActionSheet';
import { useUnsavedChangesGuard } from '@/Utils/useUnsavedChangesGuard';
import { formatUsd } from '@/Utils/money';

const methods = ['CHECK', 'ACH', 'CREDIT_CARD', 'PAYPAL', 'TRANSFER', 'OTHER'];
const positive = (value) => typeof value === 'string' && !value.startsWith('-') && /[1-9]/.test(value);
function defaults(row) {
    const values = { project_id: row.group_project_id ?? null, review_version: row.review_version ?? null,
        reference: '', method: '', stage: '', paid_on: row.normalized.U ?? '',
        fiea_reference: '', fiea_method: methods.includes(row.normalized.Z) ? row.normalized.Z : '',
        fiea_stage: '', fiea_paid_on: row.normalized.Y ?? '', note: '', ...row.resolutions };
    for (const key of ['reference', 'method', 'stage', 'paid_on', 'fiea_reference', 'fiea_method', 'fiea_stage', 'fiea_paid_on', 'note']) {
        if (typeof values[key] !== 'string') values[key] = '';
    }
    return values;
}
function ready(row, resolution, sheet) {
    if (!row.can_apply) return false;
    if (row.section === 'control_group') return Boolean(resolution.project_id);
    const eia = positive(sheet === '2025' ? row.normalized.M : row.normalized.T);
    const fiea = sheet === '2026' && positive(row.normalized.X);
    if (eia && !(resolution.stage && resolution.paid_on && resolution.reference.trim() && resolution.method)) return false;
    if (fiea && !(resolution.fiea_stage && resolution.fiea_paid_on && resolution.fiea_reference.trim() && resolution.fiea_method)) return false;
    if (sheet === '2026' && row.normalized.W != null && !resolution.fiea_stage) return false;
    return !(row.normalized.lifecycle_status === 'CERRADO' && (eia || fiea) && resolution.note.trim().length < 10);
}
export default function FinancialImportPanel({ preview }) {
    const actor = usePage().props.auth.user;
    const upload = useForm({ file: null, sheet: '2026', locale: '' });
    const apply = useForm({ import_id: null, row_ids: [], resolutions: {} });
    const file = useRef(null);
    const [confirming, setConfirming] = useState(false);
    const rows = preview?.rows ?? [];
    const identity = `${preview?.id ?? ''}:${rows.map((row) => row.id).join(',')}`;
    useEffect(() => {
        const next = { import_id: preview?.id ?? null, row_ids: [], resolutions: {} };
        apply.setData(next); apply.setDefaults(next); apply.clearErrors();
    }, [identity]);
    const guard = useUnsavedChangesGuard(apply.isDirty && !apply.processing);
    const selected = rows.filter((row) => apply.data.row_ids.includes(row.id));
    const canApply = selected.length > 0 && selected.every((row) => ready(row, apply.data.resolutions[row.id] ?? defaults(row), preview.sheet));
    const change = (row, key, value) => apply.setData('resolutions', { ...apply.data.resolutions,
        [row.id]: { ...defaults(row), ...apply.data.resolutions[row.id], [key]: value } });
    const toggle = (row) => {
        const checked = apply.data.row_ids.includes(row.id);
        apply.setData({ ...apply.data, row_ids: checked ? apply.data.row_ids.filter((id) => id !== row.id) : [...apply.data.row_ids, row.id],
            resolutions: checked ? Object.fromEntries(Object.entries(apply.data.resolutions).filter(([id]) => Number(id) !== row.id))
                : { ...apply.data.resolutions, [row.id]: defaults(row) } });
    };
    return <section className="glass-panel space-y-4 p-5">
        <h2 className="text-lg font-semibold">Importación contable</h2>
        <p>Selecciona la hoja y revisa las filas. Los cobros necesitan su evidencia y los totales emitidos se conservan.</p>
        <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => {
            event.preventDefault(); guard.request(() => upload.post(route('accounting-import.preview'), { forceFormData: true, preserveScroll: true,
                onSuccess: () => { upload.reset('file'); if (file.current) file.current.value = ''; } }));
        }}>
            <label>Archivo XLSX<input ref={file} type="file" accept=".xlsx" className="ios-field block" disabled={apply.processing}
                onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)} /></label>
            <label>Hoja<select aria-label="Hoja" className="ios-field block" value={upload.data.sheet} onChange={(event) => upload.setData('sheet', event.target.value)}>
                <option value="2025">2025</option><option value="2026">2026</option></select></label>
            <label>Formato de importes escritos como texto<select aria-label="Formato de importes escritos como texto" className="ios-field block" value={upload.data.locale} onChange={(event) => upload.setData('locale', event.target.value)}>
                <option value="">Sin conversión regional</option><option value="es_EC">Decimal con coma</option><option value="en_US">Decimal con punto y miles con coma</option></select></label>
            <button className="glass-button px-5" disabled={!upload.data.file || upload.processing || apply.processing}>Previsualizar</button>
        </form>
        {Object.entries(upload.errors).map(([key, message]) => <p role="alert" key={key}>{message}</p>)}
        {preview && <>
            <p>Hoja {preview.sheet} · Válidas {preview.summary.valid} · Inválidas {preview.summary.invalid} · Aplicadas {preview.summary.applied}</p>
            <p className="break-all text-xs">Archivo SHA-256: {preview.sha256}</p>
            <div className="financial-scroll" tabIndex={0} role="region" aria-label="Previsualización de importación">
                <table className="financial-table"><caption className="text-left">Selecciona explícitamente las filas que aplicarás</caption>
                    <thead><tr><th>Seleccionar</th><th>Fila y código</th><th>Estado de origen</th><th>DR / WODR</th><th>Revisión</th></tr></thead>
                    <tbody>{rows.map((row) => {
                        const chosen = apply.data.row_ids.includes(row.id);
                        const resolution = apply.data.resolutions[row.id] ?? defaults(row);
                        return <tr key={row.id}>
                            <td><input type="checkbox" aria-label={`Seleccionar fila ${row.row} ${row.code}`} checked={chosen}
                                disabled={!row.can_apply || apply.processing} onChange={() => toggle(row)} /></td>
                            <th scope="row">{row.row} · {row.code || 'Sin código'}</th><td>{row.normalized.lifecycle_status ?? row.accounting_status}</td>
                            <td>{formatUsd(row.total_dr)} / {formatUsd(row.total_wodr)}</td>
                            <td>{row.errors.map((error) => <p key={error} role="alert">{error}</p>)}
                                {!row.can_apply && !row.errors.length && <p>Revisa el acceso, los integrantes y la vigencia del expediente.</p>}
                                <details><summary>Ver valores originales</summary><dl>{Object.entries(row.raw).map(([key, cell]) =>
                                    <div key={key}><dt>{key}</dt><dd>{cell.value ?? 'Vacío'}</dd></div>)}</dl></details>
                                {chosen && <fieldset disabled={apply.processing} className="mt-3 space-y-2">
                                    <legend>Resoluciones de la fila {row.row}</legend>
                                    {row.section === 'case' && <>
                                        <label className="block">Etapa del cobro EIA<select aria-label="Etapa del cobro EIA" className="ios-field" value={resolution.stage} onChange={(event) => change(row, 'stage', event.target.value)}>
                                            <option value="">Revisar</option><option value="initial">Initial</option><option value="final">Final</option></select></label>
                                        <label className="block">Fecha del cobro EIA<input type="date" className="ios-field" value={resolution.paid_on} onChange={(event) => change(row, 'paid_on', event.target.value)} /></label>
                                        <label className="block">Referencia EIA<input className="ios-field" maxLength={255} value={resolution.reference} onChange={(event) => change(row, 'reference', event.target.value)} /></label>
                                        <label className="block">Método EIA<select aria-label="Método EIA" className="ios-field" value={resolution.method} onChange={(event) => change(row, 'method', event.target.value)}><option value="">Revisar</option>{methods.map((method) => <option key={method}>{method}</option>)}</select></label>
                                        {preview.sheet === '2026' && <>
                                            <label className="block">Etapa FIEA<select className="ios-field" value={resolution.fiea_stage} onChange={(event) => change(row, 'fiea_stage', event.target.value)}><option value="">Revisar</option><option value="initial">Initial</option><option value="final">Final</option></select></label>
                                            <label className="block">Fecha FIEA<input type="date" className="ios-field" value={resolution.fiea_paid_on} onChange={(event) => change(row, 'fiea_paid_on', event.target.value)} /></label>
                                            <label className="block">Referencia FIEA<input className="ios-field" maxLength={255} value={resolution.fiea_reference} onChange={(event) => change(row, 'fiea_reference', event.target.value)} /></label>
                                            <label className="block">Método FIEA<select className="ios-field" value={resolution.fiea_method} onChange={(event) => change(row, 'fiea_method', event.target.value)}><option value="">Revisar</option>{methods.map((method) => <option key={method}>{method}</option>)}</select></label>
                                        </>}
                                        <label className="block">Estado legado a confirmar<select className="ios-field" value={resolution.lifecycle_status ?? ''} onChange={(event) => change(row, 'lifecycle_status', event.target.value || null)}><option value="">Conservar estado actual</option>{row.normalized.lifecycle_status && <option>{row.normalized.lifecycle_status}</option>}</select></label>
                                    </>}
                                    {row.section === 'control_group' && <p>Proyecto de destino revisado: {row.group_project_id ?? 'Sin resolver'}</p>}
                                    <label className="block">Motivo y evidencia<textarea className="ios-field w-full" maxLength={2000} value={resolution.note} onChange={(event) => change(row, 'note', event.target.value)} /></label>
                                </fieldset>}
                            </td>
                        </tr>;
                    })}</tbody>
                </table>
            </div>
            <nav className="flex gap-3" aria-label="Páginas de la importación">
                {preview.prev_page_url && <Link href={preview.prev_page_url} preserveScroll className="glass-button px-4">Página anterior</Link>}
                {preview.next_page_url && <Link href={preview.next_page_url} preserveScroll className="glass-button px-4">Página siguiente</Link>}
            </nav>
            <p role="status">Filas seleccionadas: {selected.length}. Completa las resoluciones de los cobros antes de continuar.</p>
            {Object.entries(apply.errors).map(([key, message]) => <p role="alert" key={key}>{message}</p>)}
            <button type="button" className="glass-button px-5" disabled={!canApply || apply.processing} onClick={() => setConfirming(true)}>Revisar aplicación</button>
        </>}
        <FinancialActionSheet open={confirming} title="Aplicar filas seleccionadas"
            description={`Usuario ${actor.username}. Hoja ${preview?.sheet}. Se aplicarán únicamente ${selected.length} filas revisadas.`}
            confirmLabel="Aplicar importación" busy={apply.processing} confirmDisabled={!canApply}
            onCancel={() => setConfirming(false)} onConfirm={() => apply.post(route('accounting-import.apply'), {
                preserveScroll: true, onSuccess: () => apply.reset(), onFinish: () => setConfirming(false),
            })} />
        <FinancialActionSheet {...guard.dialogProps} />
    </section>;
}
