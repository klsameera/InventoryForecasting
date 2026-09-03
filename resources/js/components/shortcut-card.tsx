import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ComponentType } from 'react';

type Props = {
    href: NonNullable<InertiaLinkProps['href']>;
    icon: ComponentType<{ 'aria-hidden'?: boolean | 'true' | 'false' }>;
    title: string;
    description?: string;
};

/**
 * One-tap entry point to a common action. Keep these to the handful of things
 * people do most on a given page.
 */
export default function ShortcutCard({
    href,
    icon: Icon,
    title,
    description,
}: Props) {
    return (
        <Link href={href} className="app-shortcut">
            <span className="app-shortcut__icon">
                <Icon aria-hidden="true" />
            </span>

            <span className="flex-grow-1 app-min-w-0">
                <span className="app-shortcut__title d-block">{title}</span>
                {description && (
                    <span className="app-shortcut__description d-block">
                        {description}
                    </span>
                )}
            </span>

            <ChevronRight
                className="app-shortcut__chevron"
                aria-hidden="true"
            />
        </Link>
    );
}
