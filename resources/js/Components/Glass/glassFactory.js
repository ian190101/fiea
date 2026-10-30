/** @typedef {import('@ybouane/liquidglass').GlassConfig} GlassConfig */
const base = { cornerRadius: 32, zRadius: 45, bevelMode: 0, refraction: 0.69, blurAmount: 0.42, chromAberration: 0.04, edgeHighlight: 0.08, specular: 0.40, fresnel: 1, shadowOpacity: 0.28, shadowSpread: 16, shadowOffsetY: 4, opacity: 1, saturation: 0, tintStrength: 0, brightness: 0, distortion: 0, floating: false, button: false };
const presets = { panel: {}, sidebar: { blurAmount: 0.92, refraction: 0.58, fresnel: 0.95 }, button: { button: true, bevelMode: 1, cornerRadius: 999, zRadius: 28, refraction: 0.78, specular: 0.65, edgeHighlight: 0.12 } };

/** @returns {GlassConfig} */
export function glassFactory(profiles, type = 'panel', overrides = {}) {
    return { ...base, ...presets[type], ...profiles?.[type], ...overrides };
}

export function syncMicroPhysics(root, config, buttonConfig) {
    if (!root) return;
    const variables = {
        '--lg-micro-blur': `${18 + config.blurAmount * 24}px`,
        '--lg-micro-saturation': `${150 + config.saturation * 40 + config.refraction * 58}%`,
        '--lg-micro-radius': `${Math.max(12, Math.min(config.cornerRadius, 32))}px`,
        '--lg-micro-edge': Math.min(0.95, 0.55 + config.edgeHighlight * 2),
        '--lg-micro-aberration': `${config.chromAberration * 10}px`,
        '--lg-micro-shadow': config.shadowOpacity,
        '--lg-button-blur': `${16 + buttonConfig.blurAmount * 20}px`,
        '--lg-button-edge': Math.min(.95, .55 + buttonConfig.edgeHighlight * 2),
        '--lg-button-shadow': buttonConfig.shadowOpacity,
    };
    const apply = (element) => Object.entries(variables).forEach(([key, value]) => {
        if (element.style.getPropertyValue(key) !== String(value)) element.style.setProperty(key, value);
    });
    apply(root);
    if (root.id === 'app-scene-root') apply(document.documentElement);
}
