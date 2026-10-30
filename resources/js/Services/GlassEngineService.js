import { LiquidGlass } from '@ybouane/liquidglass';
import { needsGlassSnapshot } from '@/Utils/glassCapturePolicy';

// La cola evita que una inicialización pendiente sobreviva al cambio de escena.
let lifecycle = Promise.resolve();
const active = new Set();

export class GlassEngineService {
    constructor(root) {
        this.root = root;
        this.instance = null;
        this.cancelled = false;
        this.pending = false;
        this.abort = new AbortController();
        this.refreshFrame = 0;
        this.refreshTargets = new Set();
        this.recaptureTargets = new Set();
        this.metrics = { fps: 0, surfaces: 0, status: 'inicializando' };
    }

    start() {
        lifecycle = lifecycle.catch(() => {}).then(async () => {
            await document.fonts.ready;
            if (this.cancelled || !this.root.isConnected) return;
            this.root.querySelectorAll('img').forEach((image) => { image.crossOrigin = 'anonymous'; });
            const glassElements = Array.from(this.root.children).filter((node) => node.classList.contains('lg-webgl'));
            const started = performance.now();
            const captured = new Set(), skipped = new Set();
            this.instance = await LiquidGlass.init({
                root: this.root, glassElements, signal: this.abort.signal,
                captureFilter: (element, orderedChildren, glasses) => {
                    const needed = needsGlassSnapshot(element, orderedChildren, glasses);
                    if (!['CANVAS', 'IMG', 'VIDEO'].includes(element.tagName)) (needed ? captured : skipped).add(element);
                    return needed;
                },
            });
            if (this.cancelled) { this.release(); return; }
            active.add(this);
            this.root.dataset.glassStatus = 'ready';
            this.root.dataset.glassSurfaces = String(glassElements.length);
            this.root.dataset.glassStartupMs = String(Math.round(performance.now() - started));
            this.root.dataset.glassCapturedSnapshots = String(captured.size);
            this.root.dataset.glassSkippedSnapshots = String(skipped.size);
            this.metrics = { fps: 0, surfaces: glassElements.length, status: 'WebGL activo' };
            this.root.dispatchEvent(new CustomEvent('glass:ready'));
        }).catch((error) => {
            if (this.cancelled || error.name === 'AbortError') return;
            this.root.dataset.glassStatus = 'error';
            this.metrics.status = 'WebGL no disponible';
            window.dispatchEvent(new CustomEvent('glass:failure', { detail: error }));
        });
        return lifecycle;
    }

    markChanged(target, recapture = false) {
        if (!this.instance || this.cancelled) return;
        this.refreshTargets.add(target ?? this.root);
        if (recapture) this.recaptureTargets.add(target ?? this.root);
        // Un solo refresco por frame; una petición de sombreado nunca se convierte en captura HTML.
        if (!this.refreshFrame && !this.pending) this.refreshFrame = requestAnimationFrame(() => { void this.flushChanges(); });
    }

    async flushChanges() {
        this.refreshFrame = 0;
        if (!this.instance || this.cancelled) return;
        const targets = new Set(this.refreshTargets), recaptures = new Set(this.recaptureTargets);
        this.refreshTargets.clear(); this.recaptureTargets.clear();
        this.pending = true;
        const instance = this.instance;
        try {
            // markChanged vuelve a sombrear; el caché de DOM requiere invalidación explícita en 1.0.3.
            for (const target of recaptures) {
                const candidates = target === this.root ? Array.from(this.root.children) : [target];
                for (const node of candidates) {
                    if (!node.classList.contains('lg-webgl') && !['CANVAS', 'IMG', 'VIDEO'].includes(node.tagName)
                        && instance.needsSnapshot(node)) {
                        instance.capture.invalidateCache(node);
                        await instance.capture.captureElement(node, true);
                        if (this.cancelled) return;
                    }
                }
            }
            for (const target of targets) instance.markChanged(target === this.root ? undefined : target);
        } catch (error) {
            if (!this.cancelled) window.dispatchEvent(new CustomEvent('glass:failure', { detail: error }));
        } finally {
            this.pending = false;
            if (this.refreshTargets.size && !this.cancelled) this.refreshFrame = requestAnimationFrame(() => { void this.flushChanges(); });
        }
    }

    release() {
        if (!this.instance) return;
        // destroy libera recursos; perder el contexto libera también el cupo del navegador.
        const context = this.instance.renderer.gl;
        this.instance.destroy();
        context.getExtension('WEBGL_lose_context')?.loseContext();
        this.instance = null;
        active.delete(this);
    }

    destroy() {
        this.cancelled = true;
        this.abort.abort();
        cancelAnimationFrame(this.refreshFrame);
        this.refreshTargets.clear(); this.recaptureTargets.clear();
        this.release();
    }

    static refresh(target, recapture = false) {
        active.forEach((engine) => {
            if (!target || target.closest('.scene-root') === engine.root) void engine.markChanged(target === engine.root ? undefined : target, recapture);
        });
    }

    static diagnostics() {
        return Array.from(active, (engine) => ({ ...engine.metrics, fps: Math.round(engine.instance?.fps ?? 0), root: engine.root.id }));
    }
}
