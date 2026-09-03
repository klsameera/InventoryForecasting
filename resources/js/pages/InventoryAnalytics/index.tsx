import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/inventory-analytics';
import type { AbcClass, AnalyticsRow, MovementSpeed } from '@/types/analytics';
import type { Option } from '@/types/catalog';
import type { Paginated, StatusTone, TableSort } from '@/types/ui';

type StatusOption<T extends string> = { value: T; label: string };

type Filters = {
    search?: string;
    warehouse_id?: string;
    category_id?: string;
    movement_speed?: string;
    abc_class?: string;
    needs_reorder?: string;
    lookback_days?: string;
    safety_days?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    rows: Paginated<AnalyticsRow>;
    filters: Filters;
    warehouseOptions: Option[];
    categoryOptions: Option[];
    movementSpeeds: StatusOption<MovementSpeed>[];
    abcClasses: StatusOption<AbcClass>[];
};

const SPEED_TONES: Record<MovementSpeed, StatusTone> = {
    fast: 'success',
    slow: 'warning',
    dead: 'danger',
    not_applicable: 'secondary',
};

const ABC_TONES: Record<AbcClass, StatusTone> = {
    a: 'success',
    b: 'info',
    c: 'secondary',
    unclassified: 'secondary',
};

export default function InventoryAnalyticsIndex({
    rows,
    filters,
    warehouseOptions,
    categoryOptions,
    movementSpeeds,
    abcClasses,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const [movementSpeed, setMovementSpeed] = useState(
        filters.movement_speed ?? '',
    );
    const [abcClass, setAbcClass] = useState(filters.abc_class ?? '');
    const [needsReorder, setNeedsReorder] = useState(
        filters.needs_reorder === '1',
    );
    const [lookbackDays, setLookbackDays] = useState(
        filters.lookback_days ?? '30',
    );
    const [safetyDays, setSafetyDays] = useState(filters.safety_days ?? '7');
    const debouncedSearch = useDebouncedValue(search);

    const sort: TableSort | null = filters.sort
        ? {
              column: filters.sort,
              direction: filters.direction === 'desc' ? 'desc' : 'asc',
          }
        : null;

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                warehouse_id: warehouseId || undefined,
                category_id: categoryId || undefined,
                movement_speed: movementSpeed || undefined,
                abc_class: abcClass || undefined,
                needs_reorder: needsReorder ? '1' : undefined,
                lookback_days: lookbackDays,
                safety_days: safetyDays,
                sort: filters.sort,
                direction: filters.direction,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    }

    useEffect(() => {
        if (debouncedSearch === (filters.search ?? '')) {
            return;
        }

        reload({ search: debouncedSearch || undefined, page: 1 });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const canReset =
        search !== '' ||
        warehouseId !== '' ||
        categoryId !== '' ||
        movementSpeed !== '' ||
        abcClass !== '' ||
        needsReorder;

    const columns: Column<AnalyticsRow>[] = [
        {
            key: 'sku',
            header: 'SKU',
            sortable: true,
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product_name} · {row.category_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => row.warehouse_name,
        },
        {
            key: 'available_qty',
            header: 'Available',
            numeric: true,
            sortable: true,
            cell: (row) => row.available_qty,
        },
        {
            key: 'daily_velocity',
            header: 'Velocity/day',
            numeric: true,
            sortable: true,
            cell: (row) => row.daily_velocity.toFixed(2),
        },
        {
            key: 'days_of_stock',
            header: 'Days of stock',
            numeric: true,
            sortable: true,
            cell: (row) =>
                row.days_of_stock !== null ? row.days_of_stock : '—',
        },
        {
            key: 'turnover_ratio',
            header: 'Turnover',
            numeric: true,
            sortable: true,
            cell: (row) =>
                row.turnover_ratio !== null ? row.turnover_ratio : '—',
        },
        {
            key: 'weighted_age_days',
            header: 'Avg. age',
            numeric: true,
            sortable: true,
            cell: (row) =>
                row.weighted_age_days !== null
                    ? `${row.weighted_age_days}d`
                    : '—',
        },
        {
            key: 'revenue_period',
            header: 'Revenue',
            numeric: true,
            sortable: true,
            cell: (row) => row.revenue_period.toFixed(2),
        },
        {
            key: 'abc_class',
            header: 'ABC',
            cell: (row) => (
                <StatusBadge
                    tone={ABC_TONES[row.abc_class]}
                    label={row.abc_class_label}
                />
            ),
        },
        {
            key: 'movement_speed',
            header: 'Movement',
            cell: (row) => (
                <StatusBadge
                    tone={SPEED_TONES[row.movement_speed]}
                    label={row.movement_speed_label}
                />
            ),
        },
        {
            key: 'reorder_point',
            header: 'Reorder point',
            numeric: true,
            cell: (row) =>
                row.reorder_point !== null ? (
                    <span
                        className={
                            row.needs_reorder ? 'text-danger fw-semibold' : ''
                        }
                    >
                        {row.reorder_point}
                        {row.needs_reorder ? ' ⚠' : ''}
                    </span>
                ) : (
                    '—'
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Inventory"
                title="Inventory analytics"
                description="Deterministic metrics computed from actual sales and stock history — no forecasting yet."
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => `${row.warehouse_id}-${row.sku_id}`}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No data yet"
                emptyDescription="Analytics need at least one SKU with an inventory balance to show anything."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU or product…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            setCategoryId('');
                            setMovementSpeed('');
                            setAbcClass('');
                            setNeedsReorder(false);
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                category_id: undefined,
                                movement_speed: undefined,
                                abc_class: undefined,
                                needs_reorder: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={warehouseId}
                            onChange={(event) => {
                                setWarehouseId(event.target.value);
                                reload({
                                    warehouse_id:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by warehouse"
                        >
                            <option value="">All warehouses</option>
                            {warehouseOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={categoryId}
                            onChange={(event) => {
                                setCategoryId(event.target.value);
                                reload({
                                    category_id:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by category"
                        >
                            <option value="">All categories</option>
                            {categoryOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={movementSpeed}
                            onChange={(event) => {
                                setMovementSpeed(event.target.value);
                                reload({
                                    movement_speed:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by movement speed"
                        >
                            <option value="">All movement speeds</option>
                            {movementSpeeds.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={abcClass}
                            onChange={(event) => {
                                setAbcClass(event.target.value);
                                reload({
                                    abc_class: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by ABC class"
                        >
                            <option value="">All ABC classes</option>
                            {abcClasses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>

                        <div className="form-check">
                            <input
                                id="needs_reorder"
                                type="checkbox"
                                className="form-check-input"
                                checked={needsReorder}
                                onChange={(event) => {
                                    setNeedsReorder(event.target.checked);
                                    reload({
                                        needs_reorder: event.target.checked
                                            ? '1'
                                            : undefined,
                                        page: 1,
                                    });
                                }}
                            />
                            <label
                                className="form-check-label"
                                htmlFor="needs_reorder"
                            >
                                Needs reorder only
                            </label>
                        </div>

                        <div className="d-flex align-items-center gap-2">
                            <label
                                className="form-label small mb-0"
                                htmlFor="lookback_days"
                            >
                                Lookback
                            </label>
                            <input
                                id="lookback_days"
                                type="number"
                                min={1}
                                className="form-control"
                                style={{ maxWidth: '5rem' }}
                                value={lookbackDays}
                                onChange={(event) =>
                                    setLookbackDays(event.target.value)
                                }
                                onBlur={() => reload({ page: 1 })}
                                aria-label="Lookback days"
                            />
                            <label
                                className="form-label small mb-0"
                                htmlFor="safety_days"
                            >
                                Safety
                            </label>
                            <input
                                id="safety_days"
                                type="number"
                                min={0}
                                className="form-control"
                                style={{ maxWidth: '5rem' }}
                                value={safetyDays}
                                onChange={(event) =>
                                    setSafetyDays(event.target.value)
                                }
                                onBlur={() => reload({ page: 1 })}
                                aria-label="Safety days"
                            />
                        </div>
                    </TableFilters>
                }
            />
        </>
    );
}
