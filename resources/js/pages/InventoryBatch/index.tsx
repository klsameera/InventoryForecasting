import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/inventory-batch';
import type { Option } from '@/types/catalog';
import type { InventoryBatch } from '@/types/sales-purchasing';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sku_id?: string;
    open_only?: string;
};

type Props = {
    batches: Paginated<InventoryBatch>;
    filters: Filters;
    warehouseOptions: Option[];
    skuOptions: { id: number; sku: string }[];
};

export default function InventoryBatchIndex({
    batches,
    filters,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const [openOnly, setOpenOnly] = useState(filters.open_only === '1');
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                warehouse_id: warehouseId || undefined,
                sku_id: skuId || undefined,
                open_only: openOnly ? '1' : undefined,
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
        search !== '' || warehouseId !== '' || skuId !== '' || openOnly;

    const columns: Column<InventoryBatch>[] = [
        {
            key: 'sku',
            header: 'SKU',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku?.sku ?? '—'}</p>
                    <p className="app-text-muted small mb-0">
                        {row.sku?.product_name ?? '—'}
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
            key: 'received_date',
            header: 'Received',
            cell: (row) => row.received_date,
        },
        {
            key: 'age_days',
            header: 'Age',
            numeric: true,
            cell: (row) => `${row.age_days} days`,
        },
        {
            key: 'received_qty',
            header: 'Received',
            numeric: true,
            cell: (row) => row.received_qty,
        },
        {
            key: 'remaining_qty',
            header: 'Remaining',
            numeric: true,
            cell: (row) => row.remaining_qty,
        },
        {
            key: 'unit_cost',
            header: 'Unit cost',
            numeric: true,
            cell: (row) => row.unit_cost.toFixed(2),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Inventory"
                title="Inventory batches"
                description="FIFO lots behind every balance, oldest first — the basis for ageing analysis."
            />

            <DataTable
                columns={columns}
                rows={batches.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={batches}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No batches yet"
                emptyDescription="Batches open automatically once stock is received or adjusted in."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            setSkuId('');
                            setOpenOnly(false);
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                sku_id: undefined,
                                open_only: undefined,
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
                            value={skuId}
                            onChange={(event) => {
                                setSkuId(event.target.value);
                                reload({
                                    sku_id: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by SKU"
                        >
                            <option value="">All SKUs</option>
                            {skuOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.sku}
                                </option>
                            ))}
                        </select>

                        <div className="form-check">
                            <input
                                id="open_only"
                                type="checkbox"
                                className="form-check-input"
                                checked={openOnly}
                                onChange={(event) => {
                                    setOpenOnly(event.target.checked);
                                    reload({
                                        open_only: event.target.checked
                                            ? '1'
                                            : undefined,
                                        page: 1,
                                    });
                                }}
                            />
                            <label
                                className="form-check-label"
                                htmlFor="open_only"
                            >
                                Open batches only
                            </label>
                        </div>
                    </TableFilters>
                }
            />
        </>
    );
}
