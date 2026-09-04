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

/**
 * Money, compacted for a stat tile: "LKR 224.1M".
 *
 * The currency arrives as a code from `services.buyabans.currency` rather than
 * a symbol hard-coded here. Nothing in this application converts between
 * currencies, so the code is a label on a figure — and a label that can be
 * wrong should be wrong in exactly one place.
 */
export function formatMoneyCompact(value: number, currency: string): string {
    return `${currency} ${formatCompact(value)}`;
}

/**
 * Money in full: "LKR 18,394". For figures small enough to read exactly, where
 * compacting to "18.4K" would throw away the digits that matter.
 */
export function formatMoney(value: number, currency: string): string {
    return `${currency} ${Math.round(value).toLocaleString()}`;
}
