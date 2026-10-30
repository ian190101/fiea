import { useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useGlassScene } from './GlassScene';
import { glassFactory } from './glassFactory';

// El ancla conserva el flujo del formulario; el botón real es hermano de los macrocristales.
export default function SceneButton({ children, className = '', ...props }) {
    const anchor = useRef(null);
    const { root, profiles } = useGlassScene() ?? {};
    const [position, setPosition] = useState(null);
    useLayoutEffect(() => {
        if (!root || !anchor.current) return undefined;
        let frame = 0;
        const locate = () => {
            const box = anchor.current.getBoundingClientRect(), scene = root.getBoundingClientRect();
            const next = { left: box.left - scene.left, top: box.top - scene.top, width: box.width, height: box.height };
            setPosition((previous) => previous && Object.keys(next).every((key) => Math.abs(previous[key] - next[key]) < .5) ? previous : next);
        };
        locate();
        const schedule = () => { if (!frame) frame = requestAnimationFrame(() => { frame = 0; locate(); }); };
        const observer = new ResizeObserver(schedule); observer.observe(root); observer.observe(anchor.current);
        window.addEventListener('scroll', schedule, { capture: true, passive: true }); window.addEventListener('resize', schedule);
        return () => { cancelAnimationFrame(frame); observer.disconnect(); window.removeEventListener('scroll', schedule, true); window.removeEventListener('resize', schedule); };
    }, [root]);
    return <><span ref={anchor} className={`scene-button-anchor ${className}`} aria-hidden="true" />
        {root && position && createPortal(<button {...props} className={`lg-webgl scene-action ${className}`} data-config={JSON.stringify(glassFactory(profiles, 'button', { floating: false }))} style={position}>
            <span className="lg-content">{children}</span>
        </button>, root)}
    </>;
}
