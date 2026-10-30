import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { applyTheme } from './Utils/theme';
import { readPreference } from './Utils/preferences';
import { installGlobalErrors } from './Services/ErrorService';
import AppErrorBoundary from './Components/AppErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

applyTheme(readPreference('theme_preference', 'light'));
installGlobalErrors(window.axios);

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<AppErrorBoundary><App {...props} /></AppErrorBoundary>);
    },
    progress: {
        color: '#4B5563',
    },
});
