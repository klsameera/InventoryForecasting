import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export const DEFAULT_PER_PAGE = 20;
export const PER_PAGE_OPTIONS = [20, 50, 100] as const;

type Props = {
    currentPage: number;
    lastPage: number;
    onPageChange: (page: number) => void;
    disabled?: boolean;
};

/**
 * Windowed page list: first, last, and a run around the current page, with
 * ellipses standing in for the gaps.
 */
function buildPages(
    currentPage: number,
    lastPage: number,
): Array<number | '…'> {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    const pages: Array<number | '…'> = [1];
    const start = Math.max(2, currentPage - 1);
    const end = Math.min(lastPage - 1, currentPage + 1);

    if (start > 2) {
        pages.push('…');
    }

    for (let page = start; page <= end; page++) {
        pages.push(page);
    }

    if (end < lastPage - 1) {
        pages.push('…');
    }

    pages.push(lastPage);

    return pages;
}

export default function Pagination({
    currentPage,
    lastPage,
    onPageChange,
    disabled = false,
}: Props) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav aria-label="Pagination">
            <ul className="app-pagination">
                <li className="app-pagination__item">
                    <button
                        type="button"
                        className="app-pagination__link"
                        onClick={() => onPageChange(currentPage - 1)}
                        disabled={disabled || currentPage <= 1}
                        aria-label="Previous page"
                    >
                        <ChevronLeft aria-hidden="true" />
                    </button>
                </li>

                {buildPages(currentPage, lastPage).map((page, index) =>
                    page === '…' ? (
                        <li
                            key={`gap-${index}`}
                            className="app-pagination__ellipsis"
                            aria-hidden="true"
                        >
                            …
                        </li>
                    ) : (
                        <li key={page} className="app-pagination__item">
                            <button
                                type="button"
                                className={cn(
                                    'app-pagination__link',
                                    page === currentPage && 'is-active',
                                )}
                                onClick={() => onPageChange(page)}
                                disabled={disabled}
                                aria-current={
                                    page === currentPage ? 'page' : undefined
                                }
                            >
                                {page}
                            </button>
                        </li>
                    ),
                )}

                <li className="app-pagination__item">
                    <button
                        type="button"
                        className="app-pagination__link"
                        onClick={() => onPageChange(currentPage + 1)}
                        disabled={disabled || currentPage >= lastPage}
                        aria-label="Next page"
                    >
                        <ChevronRight aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </nav>
    );
}
