import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

type Props = {
    /** Hide the wordmark, e.g. in the collapsed sidebar. */
    markOnly?: boolean;
    className?: string;
};

export function AppLogoMark({ className }: { className?: string }) {
    return (
        <span className={cn('app-logo-mark', className)} aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
                <path
                    d="M12 2.6 21 7.3v9.4L12 21.4 3 16.7V7.3L12 2.6Z"
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinejoin="round"
                />
                <path
                    d="M7.4 14.6v-3.1M12 14.6V9.1M16.6 14.6v-4.3"
                    stroke="currentColor"
                    strokeWidth="1.9"
                    strokeLinecap="round"
                />
            </svg>
        </span>
    );
}

export default function AppLogo({ markOnly = false, className }: Props) {
    const { name } = usePage().props;

    return (
        <span className={cn('app-logo', className)}>
            <AppLogoMark />
            {!markOnly && <span className="app-logo__word">{name}</span>}
        </span>
    );
}
