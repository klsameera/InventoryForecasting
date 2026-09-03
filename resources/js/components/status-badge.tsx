import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { StatusTone } from '@/types/ui';

type Props = {
    tone?: StatusTone;
    label: ReactNode;
    dot?: boolean;
    pulse?: boolean;
    outline?: boolean;
    className?: string;
};

export default function StatusBadge({
    tone = 'secondary',
    label,
    dot = true,
    pulse = false,
    outline = false,
    className,
}: Props) {
    return (
        <span
            className={cn(
                'app-badge',
                `app-badge--${tone}`,
                outline && 'app-badge--outline',
                pulse && 'app-badge--pulse',
                className,
            )}
        >
            {dot && <span className="app-badge__dot" aria-hidden="true" />}
            {label}
        </span>
    );
}
