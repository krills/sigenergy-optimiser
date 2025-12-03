import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, Root } from 'react-dom/client';

const appName = (import.meta as any).env.VITE_APP_NAME;

declare global {
    interface Window {
        //had an issue where root was being re-rendered causing a warning
        inertiaRoot: Root;
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, (import.meta as any).glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        if (!window.inertiaRoot) {
            window.inertiaRoot = createRoot(el);
        }

        window.inertiaRoot.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});