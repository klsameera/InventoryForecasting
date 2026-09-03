import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export default function TextLink({
    className,
    children,
    ...props
}: InertiaLinkProps) {
    return (
        <Link {...props} className={cn('app-text-link', className)}>
            {children}
        </Link>
    );
}
