import type { FlashToast, ToastRecord, ToastType } from '@/types/ui';

const DEFAULT_DURATION = 5000;

let toasts: ToastRecord[] = [];
let nextId = 0;

const listeners = new Set<() => void>();

const notify = (): void => {
    listeners.forEach((listener) => listener());
};

export function subscribeToToasts(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function getToasts(): ToastRecord[] {
    return toasts;
}

export function dismissToast(id: number): void {
    toasts = toasts.filter((item) => item.id !== id);
    notify();
}

export function pushToast(
    type: ToastType,
    message: string,
    duration: number = DEFAULT_DURATION,
): number {
    const id = ++nextId;

    toasts = [...toasts, { id, type, message }];
    notify();

    if (duration > 0) {
        window.setTimeout(() => dismissToast(id), duration);
    }

    return id;
}

export const toast = {
    success: (message: string) => pushToast('success', message),
    error: (message: string) => pushToast('error', message),
    warning: (message: string) => pushToast('warning', message),
    info: (message: string) => pushToast('info', message),
    fromFlash: (flash: FlashToast) => pushToast(flash.type, flash.message),
};
