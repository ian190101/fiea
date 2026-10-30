import assert from 'node:assert/strict';
import test from 'node:test';
import { readableTemplate, storedTemplate, sampleTemplate } from '../../resources/js/Utils/templateFields.js';

test('muestra nombres comprensibles y conserva los campos al guardar', () => {
    const original = 'Invoice {{invoice_code}} · {{project_name}} · {{grand_total}}';
    const visible = readableTemplate(original);
    assert.equal(visible.includes('{{'), false);
    assert.match(visible, /Código del documento/);
    assert.equal(storedTemplate(visible), original);
});

test('permite insertar campos y genera una vista previa sin marcadores técnicos', () => {
    const stored = storedTemplate('Invoice ‹Código del documento›: ‹Importe total en USD›');
    assert.equal(stored, 'Invoice {{invoice_code}}: {{grand_total}}');
    assert.equal(sampleTemplate(stored), 'Invoice FIEA-2026-001: $1,250.00');
    assert.equal(sampleTemplate('{{stage}}', { stage: 'final' }), 'Final');
});
