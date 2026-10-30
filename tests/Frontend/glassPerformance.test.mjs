import test from 'node:test';
import assert from 'node:assert/strict';
import { LiquidGlass } from '@ybouane/liquidglass';
import { needsGlassSnapshot, overlapsGlassBehindContent } from '../../resources/js/Utils/glassCapturePolicy.js';

const box = (left, top, width, height) => ({ left, top, width, height, right: left + width, bottom: top + height });
const element = (rect, tagName = 'DIV') => ({ tagName, getBoundingClientRect: () => rect, hasAttribute: () => false });

test('el contenido lateral y los formularios sin superposición no se rasterizan', () => {
    const sidebar = element(box(12, 12, 280, 700));
    const header = element(box(316, 24, 900, 100));
    const workspace = element(box(316, 450, 900, 2000));
    const order = [workspace, header, sidebar];
    const glasses = new Set([sidebar, header]);
    assert.equal(needsGlassSnapshot(workspace, order, glasses), false);
    assert.equal(needsGlassSnapshot(header, order, glasses), false);
});

test('el botón del login y la lente de prueba conservan el contenido real que refractan', () => {
    const card = element(box(300, 100, 700, 500));
    const button = element(box(750, 480, 150, 50));
    assert.equal(needsGlassSnapshot(card, [card, button], new Set([card, button])), true);
    assert.equal(needsGlassSnapshot(button, [card, button], new Set([card, button])), false);
    assert.equal(needsGlassSnapshot(card, [button, card], new Set([button, card])), false);
    assert.equal(overlapsGlassBehindContent(box(10, 10, 0, 0), box(0, 0, 50, 50)), false);
    assert.equal(overlapsGlassBehindContent(box(10, 10, 10, 10), box(35, 10, 20, 20)), true);
});

test('el motor aplica el filtro tanto al precalentamiento como al contenido mutado', async () => {
    const engine = Object.create(LiquidGlass.prototype);
    const skipped = element(box(0, 0, 100, 100));
    const needed = element(box(50, 50, 100, 100));
    const html = element(box(0, 0, 500, 800));
    const captures = [];
    Object.assign(engine, {
        _captureFilter: (el) => el === needed,
        _sortedChildren: [html, skipped, needed], glassSet: new Set([skipped, needed]),
        glassCanvases: new Map([[skipped, {}], [needed, {}]]),
        _glassContentImages: new Map(), _capturingGlassContent: false,
        capture: {
            captureToCanvas: async (el) => { captures.push(el); return {}; },
            captureElement: async (el) => { captures.push(el); return {}; },
        },
    });
    await engine._prewarmStaticCaptures();
    await engine._captureGlassContent();
    assert.deepEqual(captures, [needed]);
    assert.equal(engine._glassContentImages.has(needed), true);
    await engine._captureGlassContent(new Set([skipped]));
    assert.deepEqual(captures, [needed]);
});

test('un frame en reposo no consulta el DOM ni vuelve a ejecutar el shader', () => {
    const engine = Object.create(LiquidGlass.prototype);
    Object.assign(engine, { _globalDirty: false, _glassDirty: new Set(), _userMarkedChanged: new Set(), _hasDynamic: false, _drag: { active: false } });
    // Se ejecuta en Node sin window/document: cualquier consulta de layout hace fallar esta prueba.
    assert.doesNotThrow(() => engine._renderFrame());
});
