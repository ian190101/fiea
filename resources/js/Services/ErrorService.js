import Swal from 'sweetalert2';
import { router } from '@inertiajs/react';
import { serializeDiagnostic } from '@/Utils/errorDiagnostic';

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[character]));

let lastSignature = '';
let lastShownAt = 0;

export function showError(error) {
    const status = error?.response?.status ?? error?.status ?? error?.fullError?.status ?? 0;
    const payload = error?.response?.data ?? error ?? {};
    const networkFailure = error?.isAxiosError && !error.response;
    const title = networkFailure ? 'No se pudo conectar con el servidor.' : payload.error || payload.message || (status ? `Error ${status}` : 'No se pudo completar la operación');
    const code = networkFailure ? 'NETWORK_UNAVAILABLE' : payload.errorCode || (status ? `HTTP_${status}` : 'ERR_UNEXPECTED');
    const technical = payload.technicalMessage || error?.message || 'No se recibió más información técnica.';
    const full = serializeDiagnostic(payload.fullError ?? payload);
    const signature = `${code}:${title}:${technical}`;
    if (signature === lastSignature && Date.now() - lastShownAt < 800) return;
    lastSignature = signature;
    lastShownAt = Date.now();

    void Swal.fire({
        icon: 'error',
        title: 'Se produjo un error',
        html: `<div class="error-card"><strong>Error principal</strong><p>${escapeHtml(title)}</p></div>
            <div class="error-code">Código: ${escapeHtml(status || '—')} · ${escapeHtml(code)}</div>
            <div class="error-card"><strong>Mensaje técnico</strong><p>${escapeHtml(technical)}</p></div>
            <details class="error-details lg-error-details lg-micro-glass"><summary>Error completo · detalle técnico</summary><pre>${escapeHtml(full)}</pre><button type="button" class="error-copy lg-micro-glass">Copiar diagnóstico</button></details>`,
        confirmButtonText: 'Entendido',
        customClass: { popup: 'liquid-error-popup swal2-liquid-glass-popup lg-micro-glass', confirmButton: 'liquid-error-confirm lg-micro-glass', icon: 'liquid-error-icon' },
        didOpen: (popup) => popup.querySelector('.error-copy')?.addEventListener('click', async () => {
            const button = popup.querySelector('.error-copy');
            try {
                await navigator.clipboard.writeText(full);
                if (button) button.textContent = 'Copiado';
            } catch {
                if (button) button.textContent = 'No se pudo copiar';
            }
        }),
    });
}

export function showFormError(errors, title = 'Revisa los datos enviados') {
    const messages = Object.values(errors ?? {}).flat().map(String);
    showError({
        status: 422,
        error: title,
        errorCode: 'VALIDATION_FAILED',
        technicalMessage: messages.join(' · ') || 'El servidor rechazó los datos.',
        fullError: JSON.stringify(errors, null, 2),
    });
}

export function installGlobalErrors(axios) {
    document.addEventListener('invalid', (event) => {
        event.preventDefault();
        const fields = Array.from(event.target.form?.elements ?? [event.target]).filter((field) => field.validity && !field.validity.valid);
        showFormError(Object.fromEntries(fields.map((field) => [field.name || field.id || 'campo', field.validity.valueMissing ? 'Completa este campo obligatorio.' : 'Revisa el formato y el rango del valor ingresado.'])));
    }, true);
    axios.interceptors.response.use(undefined, (error) => {
        if (error.response?.status === 409 && error.response?.headers?.['x-inertia-location']) return Promise.reject(error);
        showError(error);
        return Promise.reject(error);
    });
    router.on('error', (event) => showFormError(event.detail.errors));
    router.on('invalid', (event) => {
        event.preventDefault();
        const response = event.detail.response;
        showError(typeof response?.data === 'object' ? { response } : {
            status: response?.status, error: 'La respuesta del servidor no pudo procesarse',
            errorCode: 'INERTIA_INVALID_RESPONSE', technicalMessage: 'La operación devolvió una respuesta inesperada.',
            fullError: { status: response?.status, endpoint: response?.config?.url, timestamp: new Date().toISOString(), response: String(response?.data ?? '').slice(0, 16000) },
        });
    });
    router.on('exception', (event) => { event.preventDefault(); showError(event.detail.exception); });
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const request = args[0];
        const url = new URL(typeof request === 'string' || request instanceof URL ? request : request.url, location.href);
        // La biblioteca también usa fetch para capturar fuentes: sus recursos no son operaciones de negocio.
        const isOperation = url.origin === location.origin && !/\.(?:css|js|woff2?|ttf|png|jpe?g|svg|webp)(?:\?|$)/i.test(url.pathname);
        try {
            const response = await originalFetch(...args);
            if (isOperation && !response.ok) {
                const payload = await response.clone().json().catch(() => ({ error: `La solicitud falló (${response.status}).` }));
                showError({ response: { status: response.status, data: payload } });
            }
            return response;
        } catch (error) {
            if (isOperation && error?.name !== 'AbortError') showError({ error: 'No se pudo conectar con el servidor.', errorCode: 'NETWORK_UNAVAILABLE', technicalMessage: error.message, fullError: { endpoint: url.pathname, timestamp: new Date().toISOString(), message: error.message } });
            throw error;
        }
    };
    window.addEventListener('glass:failure', (event) => showError({ error: 'No se pudo iniciar el motor óptico.', errorCode: 'WEBGL_INITIALIZATION_FAILED', technicalMessage: event.detail?.message ?? String(event.detail), fullError: event.detail?.stack }));
    window.addEventListener('unhandledrejection', (event) => {
        if (event.reason?.isAxiosError) return;
        showError({ error: 'Ocurrió un error inesperado', errorCode: 'CLIENT_UNHANDLED', technicalMessage: event.reason?.message ?? String(event.reason), fullError: event.reason?.stack ?? String(event.reason) });
    });
    window.addEventListener('error', (event) => {
        showError({ error: 'Ocurrió un error en la interfaz', errorCode: 'CLIENT_RUNTIME', technicalMessage: event.message, fullError: event.error?.stack ?? event.message });
    });
}
