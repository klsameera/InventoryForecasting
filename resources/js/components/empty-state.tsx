import { Inbox } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    icon?: ComponentType<{ 'aria-hidden'?: boolean | 'true' | 'false' }>;
    title: string;
    description?: string;
    action?: ReactNode;
    compact?: boolean;
    className?: string;
};

export default function EmptyState({
    icon: Icon = Inbox,
    title,
    description,
    action,
    compact = false,
    className,
}: Props) {
    return (
        <div
            className={cn(
                'app-empty',
                compact && 'app-empty--compact',
                className,
            )}
        >
            <span className="app-empty__icon">
                <Icon aria-hidden="true" />
            </span>

            <p className="app-empty__title">{title}</p>

            {description && (
                <p className="app-empty__description">{description}</p>
            )}

            {action && <div className="app-empty__actions">{action}</div>}
        </div>
    );
}
