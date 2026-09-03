import { Search, X } from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    search: string;
    onSearchChange: (value: string) => void;
    searchPlaceholder?: string;
    /** Module-specific selects and date inputs. */
    children?: ReactNode;
    onReset?: () => void;
    canReset?: boolean;
};

/**
 * Filter row for listing pages. Every module supplies its own controls through
 * `children`; search and reset are shared because every listing has them.
 */
export default function TableFilters({
    search,
    onSearchChange,
    searchPlaceholder = 'Search…',
    children,
    onReset,
    canReset = false,
}: Props) {
    return (
        <div className="app-filters">
            <div className="app-filters__search">
                <Search aria-hidden="true" />
                <input
                    type="search"
                    className="form-control"
                    value={search}
                    placeholder={searchPlaceholder}
                    aria-label={searchPlaceholder}
                    onChange={(event) => onSearchChange(event.target.value)}
                />
            </div>

            {children}

            {onReset && (
                <div className="app-filters__actions">
                    <button
                        type="button"
                        className="btn btn-quiet btn-sm"
                        onClick={onReset}
                        disabled={!canReset}
                    >
                        <X aria-hidden="true" />
                        Clear
                    </button>
                </div>
            )}
        </div>
    );
}
