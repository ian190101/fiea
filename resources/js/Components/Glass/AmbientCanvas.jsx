import { useEffect, useRef } from 'react';

const rgb = (value, fallback) => /^\d+\s+\d+\s+\d+$/.test(value.trim()) ? value.trim().split(/\s+/).join(',') : fallback;

export default function AmbientCanvas({ id }) {
    const ref = useRef(null);
    useEffect(() => {
        const canvas = ref.current, scene = canvas.parentElement;
        let fingerprint = '', frame = 0;
        const paint = () => {
            const box = canvas.getBoundingClientRect(), styles = getComputedStyle(scene);
            const dark = (scene.dataset.previewTheme ?? document.documentElement.dataset.theme) === 'dark';
            const primary = rgb(styles.getPropertyValue('--brand-primary'), '37,99,235');
            const secondary = rgb(styles.getPropertyValue('--brand-secondary'), '15,118,110');
            const accent = rgb(styles.getPropertyValue('--brand-accent'), '245,158,11');
            const key = [box.width, box.height, dark, primary, secondary, accent].join('|');
            if (key === fingerprint || !box.width || !box.height) return;
            fingerprint = key;
            const ratio = Math.min(devicePixelRatio || 1, 1.5);
            canvas.width = Math.round(box.width * ratio); canvas.height = Math.round(box.height * ratio);
            const context = canvas.getContext('2d');
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.fillStyle = dark ? '#1c1a18' : '#fafafa';
            context.fillRect(0, 0, box.width, box.height);
            const glow = (x, y, radius, color, opacity) => {
                const gradient = context.createRadialGradient(x, y, 0, x, y, radius);
                gradient.addColorStop(0, `rgba(${color},${opacity})`); gradient.addColorStop(1, `rgba(${color},0)`);
                context.fillStyle = gradient; context.fillRect(0, 0, box.width, box.height);
            };
            const size = Math.max(box.width, box.height);
            glow(box.width * .04, box.height * .05, size * .68, primary, dark ? .12 : .085);
            glow(box.width * .98, box.height * .03, size * .72, accent, dark ? .15 : .075);
            glow(box.width * .48, box.height * 1.08, size * .62, secondary, dark ? .105 : .065);
            canvas.dataset.palette = `${primary}|${secondary}|${accent}`;
            canvas.dataset.appearance = dark ? 'dark' : 'light';
            canvas.dispatchEvent(new CustomEvent('glass:ambient-changed', { bubbles: true }));
        };
        const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(paint); };
        paint();
        const resize = new ResizeObserver(schedule); resize.observe(canvas);
        const theme = new MutationObserver(schedule); theme.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        const branding = new MutationObserver(schedule); branding.observe(scene, { attributes: true, attributeFilter: ['style', 'data-preview-theme'] });
        return () => { cancelAnimationFrame(frame); resize.disconnect(); theme.disconnect(); branding.disconnect(); };
    }, []);
    // El shader lee los píxeles directamente y evita rasterizar fondos HTML con capas opacas.
    return <canvas ref={ref} id={id} className="ambient-canvas" aria-hidden="true" />;
}
