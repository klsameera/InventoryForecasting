import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AuthLayoutProps = {
    children?: ReactNode;
    title?: string;
    description?: string;
};

export type ToastType = 'success' | 'info' | 'warning' | 'error';

export type FlashToast = {
    type: ToastType;
    message: string;
};

export type ToastRecord = FlashToast & {
    id: number;
};

export type StatusTone =
    'primary' | 'success' | 'danger' | 'warning' | 'info' | 'secondary';

export type SortDirection = 'asc' | 'desc';

export type TableSort = {
    column: string;
    direction: SortDirection;
};

/**
 * Shape of Laravel's length-aware paginator once serialised to JSON.
 */
export type Paginated<TData> = {
    data: TData[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
};
