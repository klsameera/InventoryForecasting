import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/stock-movement';
import type {
    MovementTypeOption,
    Option,
    StockMovement,
} from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    movement_type?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    movements: Paginated<StockMovement>;
    filters: Filters;
    warehouseOptions: Option[];
    movementTypes: MovementTypeOption[];
};

export default function StockMovementIndex({
    movements,
    filters,
    warehouseOptions,
    movementTypes,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [movementType, setMovementType] = useState(
        filters.movement_type ?? '',
    );
    const debouncedSearch = useDebouncedValue(search);

    const sort: TableSort | null = null;

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                warehouse_id: warehouseId || undefined,
                movement_type: movementType || undefined,
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

    const canReset = search !== '' || warehouseId !== '' || movementType !== '';

    const inboundTypes = new Set([
        'PURCHASE_RECEIPT',
        'SALE_RETURN',
        'TRANSFER_IN',
        'ADJUSTMENT_IN',
        'OPENING_STOCK',
    ]);

    const columns: Column<StockMovement>[] = [
        {
            key: 'occurred_at',
            header: 'Date',
            cell: (row) => row.occurred_at ?? '—',
        },
        {
            key: 'sku',
            header: 'SKU',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku?.sku ?? '—'}</p>
                    <p className="app-text-muted small mb-0">
                        {row.sku?.product?.name ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => row.warehouse?.name ?? '—',
        },
        {
            key: 'movement_type',
            header: 'Type',
            cell: (row) => (
                <StatusBadge
                    tone={
                        inboundTypes.has(row.movement_type)
                            ? 'success'
                            : 'danger'
                    }
                    label={row.movement_type_label}
                />
            ),
        },
        {
            key: 'quantity',
            header: 'Quantity',
            numeric: true,
            cell: (row) =>
                `${inboundTypes.has(row.movement_type) ? '+' : '-'}${row.quantity}`,
        },
        {
            key: 'unit_cost',
            header: 'Unit cost',
            numeric: true,
            cell: (row) =>
                row.unit_cost !== null ? row.unit_cost.toFixed(2) : '—',
        },
        {
            key: 'user',
            header: 'Recorded by',
            cell: (row) => row.user?.name ?? '—',
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Inventory"
                title="Stock movements"
                description="Append-only ledger of every stock change. Read-only — nothing in this application writes to it."
            />

            <DataTable
                columns={columns}
                rows={movements.data}
                rowKey={(row) => row.id}
                sort={sort}
                pagination={movements}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No stock movements yet"
                emptyDescription="No stock movements recorded."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            setMovementType('');
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                movement_type: undefined,
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
                            value={movementType}
                            onChange={(event) => {
                                setMovementType(event.target.value);
                                reload({
                                    movement_type:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by movement type"
                        >
                            <option value="">All types</option>
                            {movementTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
