import assert from 'node:assert/strict';
import test from 'node:test';
import { serializeDiagnostic } from '../../resources/js/Utils/errorDiagnostic.js';

test('oculta credenciales dentro del cuerpo JSON de Axios', () => {
    const result = serializeDiagnostic({ config: { data: JSON.stringify({ username: 'ian', password: 'secreto-de-prueba', nested: { access_token: 'token-de-prueba' } }), headers: { Authorization: 'credencial-de-prueba' } } });
    for (const secret of ['secreto-de-prueba', 'token-de-prueba', 'credencial-de-prueba']) assert.equal(result.includes(secret), false);
    assert.match(result, /PROTEGIDO/);
    assert.match(result, /ian/);
});

test('tolera referencias circulares y omite cuerpos que no son JSON', () => {
    const error = new Error('Fallo controlado');
    error.config = { data: 'password=secreto', request: {} };
    error.circular = error;
    const result = serializeDiagnostic(error);
    assert.match(result, /Fallo controlado/);
    assert.match(result, /REFERENCIA CIRCULAR/);
    assert.equal(result.includes('secreto'), false);
});
