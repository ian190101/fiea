import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GlassScene, { MacroGlass } from '@/Components/Glass/GlassScene';
import LiquidToggle from '@/Components/Glass/LiquidToggle';
import HelpTooltip from '@/Components/HelpTooltip';
import PrimaryButton from '@/Components/PrimaryButton';
import { GlassEngineService } from '@/Services/GlassEngineService';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useUnsavedChangesGuard } from '@/Utils/useUnsavedChangesGuard';
import FinancialActionSheet from '@/Components/FinancialActionSheet';

const labels = {
    blurAmount: ['Desenfoque', 'Difumina los detalles detrás del cristal: 0 es nítido y 1 es el máximo.'],
    refraction: ['Refracción', 'Controla cuánto se curvan las líneas y los colores detrás de la superficie.'],
    chromAberration: ['Aberración cromática', 'Separa sutilmente los colores en los bordes de la lente.'],
    edgeHighlight: ['Luz del borde', 'Intensidad del contorno luminoso del cristal.'],
    specular: ['Reflejo especular', 'Intensidad de los reflejos de luz sobre la superficie.'],
    fresnel: ['Reflexión Fresnel', 'Refuerza el reflejo cuando la superficie se acerca al borde.'],
    distortion: ['Microdistorsión', 'Añade irregularidades ópticas finas a la superficie.'],
    cornerRadius: ['Radio de esquina', 'Redondeo de la superficie, en píxeles. Los controles pequeños limitan el radio para conservar su legibilidad.'],
    zRadius: ['Profundidad del bisel', 'Curvatura del espesor de la lente, en píxeles.'],
    opacity: ['Opacidad', 'Visibilidad de la superficie de cristal.'],
    saturation: ['Saturación', 'Reduce o intensifica los colores que atraviesan el cristal.'],
    tintStrength: ['Tinte frío', 'Añade un matiz azulado al cristal.'],
    brightness: ['Brillo', 'Oscurece o aclara la imagen refractada.'],
    shadowOpacity: ['Intensidad de sombra', 'Opacidad de la sombra que separa el cristal del fondo.'],
    shadowSpread: ['Extensión de sombra', 'Distancia de difusión de la sombra, en píxeles.'],
    shadowOffsetY: ['Desplazamiento de sombra', 'Desplazamiento vertical de la sombra, en píxeles.'],
};
const profileLabels = { panel: 'Paneles', sidebar: 'Menú', button: 'Botones' };

function GlassLoopStatus() {
    const [fps, setFps] = useState(null);
    useEffect(() => {
        // El contador se actualiza solo: no vuelve a renderizar el formulario cada segundo.
        const timer = setInterval(() => setFps(GlassEngineService.diagnostics().find((entry) => entry.root === 'glass-preview-root')?.fps ?? null), 1000);
        return () => clearInterval(timer);
    }, []);
    return <p className="mt-1 text-xs text-app-muted">Motor WebGL real · {fps ?? '—'} FPS del bucle</p>;
}

export default function LiquidGlassStudio({ profiles, defaults, ranges }) {
    const { flash } = usePage().props;
    const form = useForm({ profiles });
    const guard = useUnsavedChangesGuard(form.isDirty && !form.processing);
    const [selected, setSelected] = useState('panel');
    const [previewTheme, setPreviewTheme] = useState('light');
    const config = form.data.profiles[selected];
    const update = (key, value) => form.setData('profiles', { ...form.data.profiles, [selected]: { ...config, [key]: value } });
    const submit = (event) => {
        event.preventDefault();
        form.put(route('liquid-glass.update'), { preserveScroll: true, onSuccess: (page) => {
            form.setData('profiles', page.props.profiles);
            form.setDefaults({ profiles: page.props.profiles });
        } });
    };
    return <AuthenticatedLayout header={<h1 className="text-2xl font-bold">Estudio Liquid Glass</h1>}>
        <Head title="Estudio Liquid Glass" />
        <FinancialActionSheet {...guard.dialogProps} />
        <div className="studio-introduction glass-panel lg-micro-glass p-5">
            <p className="login-eyebrow">DISEÑO DEL SISTEMA</p>
            <h2 className="text-xl font-bold">La luz, a tu medida.</h2>
            <p className="mt-2 text-sm text-app-muted">Ajusta el cristal sobre la cuadrícula. La vista previa cambia al instante; Publicar aplica el diseño a los paneles, al menú, a los botones y al acceso.</p>
        </div>
        {flash?.success && <p role="status" className="glass-tile my-4 p-4 font-semibold">{flash.success}</p>}
        <form onSubmit={submit} className="studio-layout mt-5">
            <section className="glass-panel lg-micro-glass studio-controls p-5" aria-label="Parámetros del cristal">
                <div className="studio-tabs" role="tablist" aria-label="Perfil de cristal">
                    {Object.entries(profileLabels).map(([key, label]) => <button id={`tab-${key}`} key={key} type="button" role="tab" aria-selected={selected === key} aria-controls="optical-controls" className={`glass-button ${selected === key ? 'is-selected' : ''}`} onClick={() => setSelected(key)}>{label}</button>)}
                </div>
                <div id="optical-controls" role="tabpanel" aria-labelledby={`tab-${selected}`}>
                    {Object.entries(ranges).map(([key, [min, max, step]]) => <div key={key} className="studio-control">
                        <div className="flex items-center justify-between gap-2"><div className="flex items-center"><label htmlFor={`optical-${key}`} className="text-sm font-semibold">{labels[key][0]}</label><HelpTooltip label={labels[key][0]} text={labels[key][1]} /></div><output htmlFor={`optical-${key}`} className="optical-value">{Number(config[key]).toFixed(step < .01 ? 3 : step < 1 ? 2 : 0)}</output></div>
                        <input id={`optical-${key}`} type="range" min={min} max={max} step={step} value={config[key]} onChange={(event) => update(key, Number(event.target.value))} className="liquid-range" />
                    </div>)}
                    <div className="studio-control"><div className="flex items-center"><p className="text-sm font-semibold">Forma del bisel</p><HelpTooltip label="Forma del bisel" text="Biconvexo curva ambas caras; domo crea una lente con base plana." /></div>
                        <div className="studio-tabs mt-2">{['Biconvexo', 'Domo'].map((label, value) => <button type="button" key={label} aria-pressed={config.bevelMode === value} className={`glass-button ${config.bevelMode === value ? 'is-selected' : ''}`} onClick={() => update('bevelMode', value)}>{label}</button>)}</div>
                    </div>
                    <div className="studio-control"><LiquidToggle checked={config.button} onChange={(event) => update('button', event.target.checked)} label="Reacción al pulsar" /><HelpTooltip label="Reacción al pulsar" text="Activa los reflejos de hover y la compresión óptica del motor en este perfil." /></div>
                    <div className="studio-control"><LiquidToggle checked={config.floating} onChange={(event) => update('floating', event.target.checked)} label="Probar arrastre" /><HelpTooltip label="Probar arrastre" text="Permite arrastrar la lente de prueba. Los paneles de trabajo mantienen su posición para conservar el diseño responsivo." /></div>
                </div>
            </section>
            <aside className="studio-preview-column">
                <div className="studio-actions glass-panel lg-micro-glass mb-4 p-4">
                    <PrimaryButton type="submit" disabled={form.processing || !form.isDirty} aria-busy={form.processing}>{form.processing ? 'Publicando…' : 'Publicar cambios'}</PrimaryButton>
                    <button type="button" className="glass-button" onClick={() => form.reset()} disabled={form.processing}>Descartar</button>
                    <button type="button" className="glass-button" onClick={() => form.setData('profiles', { ...form.data.profiles, [selected]: { ...defaults[selected] } })}>Restablecer {profileLabels[selected].toLowerCase()}</button>
                    <p className="w-full text-xs text-app-muted" role="status">{form.isDirty ? 'Hay ajustes sin publicar.' : 'Vista sincronizada con la configuración guardada.'}</p>
                </div>
                <div className="studio-preview-toolbar"><div><p className="font-bold">Vista previa · {profileLabels[selected]}</p><GlassLoopStatus /></div>
                    <button type="button" className="glass-button" onClick={() => { setPreviewTheme((theme) => theme === 'light' ? 'dark' : 'light'); requestAnimationFrame(() => GlassEngineService.refresh(document.getElementById('glass-preview-root'), true)); }} aria-label={previewTheme === 'light' ? 'Previsualizar fondo oscuro' : 'Previsualizar fondo claro'}>{previewTheme === 'light' ? '◐ Oscuro' : '☀ Claro'}</button>
                </div>
                <GlassScene key={`${selected}-${config.button}-${config.floating}`} id="glass-preview-root" profiles={form.data.profiles} className="optical-preview" data-preview-theme={previewTheme}>
                    <div className="preview-type" aria-hidden="true"><span>FIEA</span><small>LUZ · FORMA · PROFUNDIDAD</small><div className="preview-color-strip"><i /><i /><i /><i /></div></div>
                    <MacroGlass profile={selected} className={`preview-lens preview-lens-${selected}`}>
                        <span className="preview-spark" aria-hidden="true">✦</span><strong>{profileLabels[selected]}</strong><p>Observa cómo se curvan las líneas y los colores en los bordes.</p>
                        <span className="preview-badge lg-micro-glass">{config.floating ? 'Arrastra para explorar' : config.button ? 'Pasa el cursor y pulsa' : 'Cristal óptico'}</span>
                    </MacroGlass>
                </GlassScene>
                <div className="glass-tile p-4 mt-4 text-sm text-app-muted"><strong className="text-app-text">Al publicar</strong><p className="mt-2">Se guarda el perfil completo. El menú conserva una capa de lectura protectora y los campos responden al escribir sin recapturar el formulario por fotograma.</p></div>
            </aside>
        </form>
    </AuthenticatedLayout>;
}
