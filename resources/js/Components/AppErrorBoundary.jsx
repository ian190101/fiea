import { Component } from 'react';
import { showError } from '@/Services/ErrorService';

export default class AppErrorBoundary extends Component {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error) {
        showError({
            error: 'No se pudo mostrar esta vista',
            errorCode: 'CLIENT_RENDER',
            technicalMessage: error.message,
            fullError: error.stack ?? String(error),
        });
    }

    render() {
        if (this.state.failed) {
            return <main className="app-shell flex min-h-screen items-center justify-center bg-app-bg p-4 text-app-text">
                <section className="glass-panel max-w-lg p-8 text-center">
                    <h1 className="text-2xl font-bold">No se pudo mostrar esta vista</h1>
                    <p className="mt-3 text-app-muted">Revisa el detalle del error y vuelve a cargar la página.</p>
                    <button type="button" className="glass-button mt-6" onClick={() => window.location.reload()}>Volver a cargar</button>
                </section>
            </main>;
        }
        return this.props.children;
    }
}
