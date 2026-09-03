import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import EmptyState from '@/components/empty-state';
import Pagination, {
    DEFAULT_PER_PAGE,
    PER_PAGE_OPTIONS,
} from '@/components/pagination';
import Spinner from '@/components/spinner';
import { cn } from '@/lib/utils';
import type { Paginated, SortDirection, TableSort } from '@/types/ui';

export type Column<TRow> = {
    key: string;
    header: string;
    /** Renders the cell. Receives the row. */
    cell: (row: TRow) => ReactNode;
    sortable?: boolean;
    numeric?: boolean;
    /** Omit on the actions column so the mobile view does not print a label. */
    hideLabelOnMobile?: boolean;
    width?: string;
};

type Props<TRow> = {
    columns: Column<TRow>[];
    rows: TRow[];
    rowKey: (row: TRow) => string | number;
    loading?: boolean;
    sort?: TableSort | null;
    onSortChange?: (sort: TableSort) => void;
    pagination?: Omit<Paginated<TRow>, 'data'>;
    onPageChange?: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
    emptyTitle?: string;
    emptyDescription?: string;
    emptyAction?: ReactNode;
    toolbar?: ReactNode;
};

function nextDirection(
    column: string,
    sort: TableSort | null | undefined,
): SortDirection {
    if (sort?.column === column && sort.direction === 'asc') {
        return 'desc';
    }

    return 'asc';
}

function SortIcon({
    active,
    direction,
}: {
    active: boolean;
    direction: SortDirection;
}) {
    if (!active) {
        return <ArrowUpDown aria-hidden="true" />;
    }

    return direction === 'asc' ? (
        <ArrowUp aria-hidden="true" />
    ) : (
        <ArrowDown aria-hidden="true" />
    );
}

/**
 * Listing table with sorting, loading, empty and paginated states. Filters are
 * passed in via `toolbar` so each module owns its own filter set.
 */
export default function DataTable<TRow>({
    columns,
    rows,
    rowKey,
    loading = false,
    sort,
    onSortChange,
    pagination,
    onPageChange,
    onPerPageChange,
    emptyTitle = 'Nothing here yet',
    emptyDescription,
    emptyAction,
    toolbar,
}: Props<TRow>) {
    const perPage = pagination?.per_page ?? DEFAULT_PER_PAGE;
    const showEmpty = !loading && rows.length === 0;

    return (
        <div className="app-card app-card--flush">
            {toolbar}

            {showEmpty ? (
                <EmptyState
                    title={emptyTitle}
                    description={emptyDescription}
                    action={emptyAction}
                />
            ) : (
                <div className="app-table-wrap">
                    {loading && (
                        <div className="app-table__overlay">
                            <Spinner />
                            Loading…
                        </div>
                    )}

                    <table className="app-table app-table--stack">
                        <thead>
                            <tr>
                                {columns.map((column) => (
                                    <th
                                        key={column.key}
                                        scope="col"
                                        style={
                                            column.width
                                                ? { width: column.width }
                                                : undefined
                                        }
                                        className={cn(
                                            column.numeric &&
                                                'app-table__numeric',
                                        )}
                                        aria-sort={
                                            sort?.column === column.key
                                                ? sort.direction === 'asc'
                                                    ? 'ascending'
                                                    : 'descending'
                                                : undefined
                                        }
                                    >
                                        {column.sortable && onSortChange ? (
                                            <button
                                                type="button"
                                                className={cn(
                                                    'app-table__sort',
                                                    sort?.column ===
                                                        column.key &&
                                                        'is-sorted',
                                                )}
                                                onClick={() =>
                                                    onSortChange({
                                                        column: column.key,
                                                        direction:
                                                            nextDirection(
                                                                column.key,
                                                                sort,
                                                            ),
                                                    })
                                                }
                                            >
                                                {column.header}
                                                <SortIcon
                                                    active={
                                                        sort?.column ===
                                                        column.key
                                                    }
                                                    direction={
                                                        sort?.direction ?? 'asc'
                                                    }
                                                />
                                            </button>
                                        ) : (
                                            column.header
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>

                        <tbody>
                            {rows.map((row) => (
                                <tr key={rowKey(row)}>
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            data-label={
                                                column.hideLabelOnMobile
                                                    ? undefined
                                                    : column.header
                                            }
                                            className={cn(
                                                column.numeric &&
                                                    'app-table__numeric',
                                            )}
                                        >
                                            {column.cell(row)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {pagination && (
                <div className="app-table-footer">
                    <div className="d-flex align-items-center gap-3 flex-wrap">
                        <span className="app-table-footer__summary">
                            {pagination.total > 0
                                ? `Showing ${pagination.from ?? 0}–${pagination.to ?? 0} of ${pagination.total.toLocaleString()}`
                                : 'No results'}
                        </span>

                        {onPerPageChange && (
                            <label className="app-per-page">
                                Rows
                                <select
                                    className="form-select"
                                    value={perPage}
                                    onChange={(event) =>
                                        onPerPageChange(
                                            Number(event.target.value),
                                        )
                                    }
                                >
                                    {PER_PAGE_OPTIONS.map((option) => (
                                        <option key={option} value={option}>
                                            {option}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>

                    {onPageChange && (
                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            onPageChange={onPageChange}
                            disabled={loading}
                        />
                    )}
                </div>
            )}
        </div>
    );
}
