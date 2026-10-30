/** Solo se rasteriza contenido que un cristal situado delante pueda refractar. */
export function overlapsGlassBehindContent(content, glass, padding = 20) {
    return content.width > 0 && content.height > 0 && glass.width > 0 && glass.height > 0
        && content.left < glass.right + padding && content.right > glass.left - padding
        && content.top < glass.bottom + padding && content.bottom > glass.top - padding;
}

export function needsGlassSnapshot(element, orderedChildren, glassSet) {
    // Los enlaces de salto accesibles están recortados hasta recibir foco; no pintan bajo el cristal.
    if (element.matches?.('.sr-only:not(:focus)')) return false;
    if (element.checkVisibility?.({ checkOpacity: true, checkVisibilityCSS: true }) === false) return false;
    const index = orderedChildren.indexOf(element);
    if (index < 0) return false;
    const bounds = element.getBoundingClientRect();
    return orderedChildren.slice(index + 1).some((candidate) => glassSet.has(candidate)
        && overlapsGlassBehindContent(bounds, candidate.getBoundingClientRect()));
}
