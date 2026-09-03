import { createInertiaApp } from '@inertiajs/react';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';
import ToastHost from '@/components/toast-host';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Inventory Forecasting';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <>
                {app}
                <ToastHost />
            </>
        );
    },
    progress: {
        color: 'var(--app-brand)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
