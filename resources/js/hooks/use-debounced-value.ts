import { useEffect, useState } from 'react';

/**
 * Returns `value`, updated only after it has stopped changing for `delayMs`.
 * Used to debounce search inputs before they drive a listing round-trip.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timeout = setTimeout(() => setDebounced(value), delayMs);

        return () => clearTimeout(timeout);
    }, [value, delayMs]);

    return debounced;
}
