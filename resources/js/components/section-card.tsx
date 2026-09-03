import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    title?: string;
    subtitle?: string;
    actions?: ReactNode;
    footer?: ReactNode;
    accent?: boolean;
    flush?: boolean;
    className?: string;
    bodyClassName?: string;
    children: ReactNode;
};

/**
 * The standard surface for page content: optional header row, body, footer.
 * Use `flush` when the body owns its own padding (tables, lists).
 */
export default function SectionCard({
    title,
    subtitle,
    actions,
    footer,
    accent = false,
    flush = false,
    className,
    bodyClassName,
    children,
}: Props) {
    return (
        <section className={cn('app-card', className)}>
            {accent && <span className="app-card__accent" aria-hidden="true" />}

            {(title || actions) && (
                <div className="app-card__header">
                    <div>
                        {title && <h2 className="app-card__title">{title}</h2>}
                        {subtitle && (
                            <p className="app-card__subtitle">{subtitle}</p>
                        )}
                    </div>
                    {actions && (
                        <div className="d-flex align-items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}

            <div className={cn(!flush && 'app-card__body', bodyClassName)}>
                {children}
            </div>

            {footer && <div className="app-card__footer">{footer}</div>}
        </section>
    );
}
