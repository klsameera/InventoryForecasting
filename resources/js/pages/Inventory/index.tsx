import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/inventory';
import type { Inventory, Option } from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    inventories: Paginated<Inventory>;
    filters: Filters;
    warehouseOptions: Option[];
};

export default function InventoryIndex({
    inventories,
    filters,
    warehouseOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
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

    const canReset = search !== '' || warehouseId !== '';

    const columns: Column<Inventory>[] = [
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
            key: 'on_hand_qty',
            header: 'On hand',
            numeric: true,
            sortable: true,
            cell: (row) => row.on_hand_qty,
        },
        {
            key: 'reserved_qty',
            header: 'Reserved',
            numeric: true,
            cell: (row) => row.reserved_qty,
        },
        {
            key: 'available_qty',
            header: 'Available',
            numeric: true,
            sortable: true,
            cell: (row) => row.available_qty,
        },
        {
            key: 'incoming_qty',
            header: 'Incoming',
            numeric: true,
            cell: (row) => row.incoming_qty,
        },
        {
            key: 'average_cost',
            header: 'Avg. cost',
            numeric: true,
            sortable: true,
            cell: (row) => row.average_cost.toFixed(2),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Inventory"
                title="Inventory"
                description="Current stock balances by warehouse, derived from the stock movement ledger. Read-only — stock is operated in the BuyAbans back office."
            />

            <DataTable
                columns={columns}
                rows={inventories.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={inventories}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No inventory yet"
                emptyDescription="No balances yet."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
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
                    </TableFilters>
                }
            />
        </>
    );
}
