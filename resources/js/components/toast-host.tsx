import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { dismissToast, getToasts, subscribeToToasts } from '@/lib/toast';
import type { ToastType } from '@/types/ui';

const ICONS = {
    success: CircleCheck,
    error: CircleAlert,
    warning: TriangleAlert,
    info: Info,
} as const;

const emptyToasts: ReturnType<typeof getToasts> = [];

export default function ToastHost() {
    const toasts = useSyncExternalStore(
        subscribeToToasts,
        getToasts,
        () => emptyToasts,
    );

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div
            className="app-toast-host"
            role="region"
            aria-label="Notifications"
        >
            {toasts.map((item) => {
                const Icon = ICONS[item.type as ToastType];

                return (
                    <div
                        key={item.id}
                        className={`app-toast app-toast--${item.type}`}
                        role={item.type === 'error' ? 'alert' : 'status'}
                    >
                        <Icon aria-hidden="true" />
                        <p className="app-toast__message mb-0">
                            {item.message}
                        </p>
                        <button
                            type="button"
                            className="app-toast__close"
                            onClick={() => dismissToast(item.id)}
                            aria-label="Dismiss notification"
                        >
                            <X aria-hidden="true" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
