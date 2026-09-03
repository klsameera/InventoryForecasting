import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { capture, index } from '@/routes/inventory-daily-snapshot';
import type { Option, SkuOption } from '@/types/catalog';
import type { InventoryDailySnapshot } from '@/types/pipeline';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sku_id?: string;
    stockout_only?: string;
    date_from?: string;
    date_to?: string;
};

type Props = {
    snapshots: Paginated<InventoryDailySnapshot>;
    filters: Filters;
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

export default function InventoryDailySnapshotIndex({
    snapshots,
    filters,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const [stockoutOnly, setStockoutOnly] = useState(
        filters.stockout_only === '1',
    );
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [captureDate, setCaptureDate] = useState('');
    const [capturing, setCapturing] = useState(false);
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                warehouse_id: warehouseId || undefined,
                sku_id: skuId || undefined,
                stockout_only: stockoutOnly ? '1' : undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
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
        skuId !== '' ||
        stockoutOnly ||
        dateFrom !== '' ||
        dateTo !== '';

    function runCapture() {
        setCapturing(true);
        router.post(capture.url(), captureDate ? { date: captureDate } : {}, {
            preserveScroll: true,
            onFinish: () => setCapturing(false),
        });
    }

    const columns: Column<InventoryDailySnapshot>[] = [
        {
            key: 'snapshot_date',
            header: 'Date',
            cell: (row) => row.snapshot_date,
        },
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
            key: 'opening_qty',
            header: 'Opening',
            numeric: true,
            cell: (row) => row.opening_qty,
        },
        {
            key: 'received_qty',
            header: 'Received',
            numeric: true,
            cell: (row) => row.received_qty,
        },
        {
            key: 'sold_qty',
            header: 'Sold',
            numeric: true,
            cell: (row) => row.sold_qty,
        },
        {
            key: 'closing_qty',
            header: 'Closing',
            numeric: true,
            cell: (row) => row.closing_qty,
        },
        {
            key: 'inventory_value',
            header: 'Value',
            numeric: true,
            cell: (row) => row.inventory_value.toFixed(2),
        },
        {
            key: 'stockout',
            header: 'Stockout',
            cell: (row) =>
                row.stockout_flag ? (
                    <StatusBadge
                        tone="danger"
                        label={`${row.stockout_minutes ?? 0} min`}
                    />
                ) : (
                    <StatusBadge tone="success" label="None" />
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Inventory"
                title="Daily snapshots"
                description="One row per warehouse/SKU per day — opening/closing balance, demand and stockout minutes. Feeds ML feature data once that phase exists."
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <input
                            type="date"
                            className="form-control"
                            value={captureDate}
                            onChange={(event) =>
                                setCaptureDate(event.target.value)
                            }
                            max={new Date().toISOString().split('T')[0]}
                            aria-label="Date to capture"
                        />
                        <button
                            type="button"
                            className="btn btn-gradient"
                            disabled={capturing}
                            onClick={runCapture}
                        >
                            {capturing ? (
                                <Spinner size="sm" />
                            ) : (
                                <RefreshCw aria-hidden="true" />
                            )}
                            Capture snapshot
                        </button>
                    </div>
                }
            />

            <DataTable
                columns={columns}
                rows={snapshots.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={snapshots}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No snapshots yet"
                emptyDescription="Capture a date above, or wait for the nightly schedule to run."
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
                            setStockoutOnly(false);
                            setDateFrom('');
                            setDateTo('');
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                sku_id: undefined,
                                stockout_only: undefined,
                                date_from: undefined,
                                date_to: undefined,
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

                        <input
                            type="date"
                            className="form-control"
                            value={dateFrom}
                            onChange={(event) => {
                                setDateFrom(event.target.value);
                                reload({
                                    date_from: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="From date"
                        />

                        <input
                            type="date"
                            className="form-control"
                            value={dateTo}
                            onChange={(event) => {
                                setDateTo(event.target.value);
                                reload({
                                    date_to: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="To date"
                        />

                        <div className="form-check">
                            <input
                                id="stockout_only"
                                type="checkbox"
                                className="form-check-input"
                                checked={stockoutOnly}
                                onChange={(event) => {
                                    setStockoutOnly(event.target.checked);
                                    reload({
                                        stockout_only: event.target.checked
                                            ? '1'
                                            : undefined,
                                        page: 1,
                                    });
                                }}
                            />
                            <label
                                className="form-check-label"
                                htmlFor="stockout_only"
                            >
                                Stockouts only
                            </label>
                        </div>
                    </TableFilters>
                }
            />
        </>
    );
}
