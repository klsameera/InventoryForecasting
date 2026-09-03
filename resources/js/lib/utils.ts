import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';

/**
 * Compose conditional class names. Bootstrap utilities do not conflict the way
 * atomic frameworks do, so plain concatenation is all this needs.
 */
export function cn(...inputs: ClassValue[]): string {
    return clsx(inputs);
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * Compact large values for stat tiles: 1284 -> "1,284", 12900 -> "12.9K".
 */
export function formatCompact(value: number): string {
    if (Math.abs(value) < 10_000) {
        return value.toLocaleString();
    }

    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

export function formatNumber(value: number): string {
    return value.toLocaleString();
}
