import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { GlassEngineService } from '@/Services/GlassEngineService';
import { glassFactory, syncMicroPhysics } from './glassFactory';
import AmbientCanvas from './AmbientCanvas';

const SceneContext = createContext(null);
export const useGlassScene = () => useContext(SceneContext);

export default function GlassScene({ children, profiles, id = 'app-scene-root', className = '', style, ...props }) {
    const rootRef = useRef(null);
    const [root, setRoot] = useState(null);
    const engineRef = useRef(null);
    useEffect(() => {
        const scene = rootRef.current;
        setRoot(scene);
        // Dos frames permiten montar los portales ópticos antes de registrar los hijos directos.
        let frame2;
        const initialize = () => {
            const anchors = scene.querySelectorAll('.scene-button-anchor').length;
            const buttons = Array.from(scene.children).filter((node) => node.classList.contains('scene-action')).length;
            if (anchors > buttons) { frame2 = requestAnimationFrame(initialize); return; }
            const engine = new GlassEngineService(scene);
            engineRef.current = engine;
            void engine.start();
        };
        const frame = requestAnimationFrame(() => { frame2 = requestAnimationFrame(initialize); });
        // El fondo ya es un canvas: cambiar de tema no requiere capturar los formularios completos.
        const themeObserver = new MutationObserver(() => { void engineRef.current?.markChanged(); });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        const ended = (event) => {
            if (['margin-left', 'width', 'grid-template-rows', 'opacity'].includes(event.propertyName)) void engineRef.current?.markChanged();
        };
        const changed = () => { void engineRef.current?.markChanged(); };
        const ambientChanged = (event) => { void engineRef.current?.markChanged(event.target); };
        scene.addEventListener('transitionend', ended);
        scene.addEventListener('glass:changed', changed);
        scene.addEventListener('glass:ambient-changed', ambientChanged);
        return () => {
            cancelAnimationFrame(frame); cancelAnimationFrame(frame2);
            themeObserver.disconnect(); scene.removeEventListener('transitionend', ended); scene.removeEventListener('glass:changed', changed);
            scene.removeEventListener('glass:ambient-changed', ambientChanged);
            engineRef.current?.destroy();
        };
    }, []);
    useEffect(() => { syncMicroPhysics(rootRef.current, glassFactory(profiles, 'panel'), glassFactory(profiles, 'button')); }, [profiles]);
    return <SceneContext.Provider value={{ root, profiles }}>
        <div ref={rootRef} id={id} className={`scene-root ${className}`} style={style} {...props}>
            <AmbientCanvas id={id === 'app-scene-root' ? 'ambient-refractive-canvas' : undefined} />
            {children}
        </div>
    </SceneContext.Provider>;
}

export function MacroGlass({ as: Tag = 'section', profile = 'panel', config, className = '', children, style, ...props }) {
    const { profiles } = useGlassScene() ?? {};
    const physics = glassFactory(profiles, profile, config);
    return <Tag {...props} className={`lg-webgl ${className}`} style={{ borderRadius: physics.cornerRadius, ...style }} data-config={JSON.stringify(physics)}>
        <div className="lg-content">{children}</div>
    </Tag>;
}
