import { Link } from '@inertiajs/react';
import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import PageHeader from '@/components/page-header';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const settingsNav: NavItem[] = [
    { title: 'Profile', href: editProfile(), icon: UserRound },
    { title: 'Security', href: editSecurity(), icon: ShieldCheck },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="app-stack">
            <PageHeader
                eyebrow="Settings"
                title="Account settings"
                description="Manage your profile, security and interface preferences."
            />

            <div className="row g-4">
                <div className="col-lg-3">
                    <nav
                        className="app-tabs app-tabs--vertical"
                        aria-label="Settings sections"
                    >
                        {settingsNav.map((item) => (
                            <Link
                                key={toUrl(item.href)}
                                href={item.href}
                                className={cn(
                                    'app-tabs__link',
                                    isCurrentOrParentUrl(item.href) &&
                                        'is-active',
                                )}
                                aria-current={
                                    isCurrentOrParentUrl(item.href)
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {item.icon && <item.icon aria-hidden="true" />}
                                {item.title}
                            </Link>
                        ))}
                    </nav>
                </div>

                <div className="col-lg-9">
                    <div className="app-stack">{children}</div>
                </div>
            </div>
        </div>
    );
}
