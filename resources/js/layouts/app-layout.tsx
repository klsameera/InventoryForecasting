import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import AppSidebar from '@/components/app-sidebar';
import AppTopbar from '@/components/app-topbar';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

const SIDEBAR_COOKIE_MAX_AGE = 365 * 24 * 60 * 60;

export default function AppLayout({
    breadcrumbs = [],
    children,
}: AppLayoutProps) {
    const { sidebarOpen } = usePage().props;
    const [collapsed, setCollapsed] = useState(!sidebarOpen);
    const [mobileOpen, setMobileOpen] = useState(false);

    useFlashToast();

    const toggleCollapsed = useCallback((): void => {
        setCollapsed((previous) => {
            const next = !previous;

            document.cookie = `sidebar_state=${!next};path=/;max-age=${SIDEBAR_COOKIE_MAX_AGE};SameSite=Lax`;

            return next;
        });
    }, []);

    return (
        <div className={cn('app-shell', collapsed && 'app-shell--collapsed')}>
            <AppSidebar
                open={mobileOpen}
                onNavigate={() => setMobileOpen(false)}
            />

            {mobileOpen && (
                <button
                    type="button"
                    className="app-backdrop"
                    onClick={() => setMobileOpen(false)}
                    aria-label="Close navigation"
                />
            )}

            <div className="app-shell__main">
                <AppTopbar
                    breadcrumbs={breadcrumbs}
                    collapsed={collapsed}
                    onOpenSidebar={() => setMobileOpen(true)}
                    onToggleCollapsed={toggleCollapsed}
                />

                <main className="app-shell__content">{children}</main>
            </div>
        </div>
    );
}
