import { Menu, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import Breadcrumbs from '@/components/breadcrumbs';
import ThemeToggle from '@/components/theme-toggle';
import UserMenu from '@/components/user-menu';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItem[];
    collapsed: boolean;
    onOpenSidebar: () => void;
    onToggleCollapsed: () => void;
};

export default function AppTopbar({
    breadcrumbs,
    collapsed,
    onOpenSidebar,
    onToggleCollapsed,
}: Props) {
    return (
        <header className="app-topbar">
            <button
                type="button"
                className="app-icon-btn d-lg-none"
                onClick={onOpenSidebar}
                aria-label="Open navigation"
            >
                <Menu aria-hidden="true" />
            </button>

            <button
                type="button"
                className="app-icon-btn d-none d-lg-inline-flex"
                onClick={onToggleCollapsed}
                aria-label={
                    collapsed ? 'Expand navigation' : 'Collapse navigation'
                }
            >
                {collapsed ? (
                    <PanelLeftOpen aria-hidden="true" />
                ) : (
                    <PanelLeftClose aria-hidden="true" />
                )}
            </button>

            <Breadcrumbs items={breadcrumbs} />

            <div className="app-topbar__spacer" />

            <ThemeToggle variant="compact" />

            <UserMenu />
        </header>
    );
}
