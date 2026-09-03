import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { lostSales as lostSalesRoute } from '@/routes/demand-insights';
import type { LostSalesRow } from '@/types/advanced-intelligence';
import type { Option, SkuOption } from '@/types/catalog';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sku_id?: string;
    lookback_days?: string;
};

type Props = {
    rows: Paginated<LostSalesRow>;
    filters: Filters;
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

export default function DemandInsightsLostSales({
    rows,
    filters,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const [lookbackDays, setLookbackDays] = useState(
        filters.lookback_days ?? '30',
    );
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            lostSalesRoute.url(),
            {
                search: debouncedSearch || undefined,
                warehouse_id: warehouseId || undefined,
                sku_id: skuId || undefined,
                lookback_days: lookbackDays,
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

    const columns: Column<LostSalesRow>[] = [
        {
            key: 'sku',
            header: 'SKU',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product_name}
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
            key: 'stockout_days',
            header: 'Stockout days',
            numeric: true,
            cell: (row) => row.stockout_days,
        },
        {
            key: 'normal_daily_rate',
            header: 'Normal daily rate',
            numeric: true,
            cell: (row) => row.normal_daily_rate.toFixed(2),
        },
        {
            key: 'estimated_lost_units',
            header: 'Estimated lost units',
            numeric: true,
            cell: (row) => (
                <span className="fw-semibold text-danger">
                    {row.estimated_lost_units.toFixed(1)}
                </span>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Lost sales"
                description="Units likely missed during real stockouts — stockout minutes multiplied by the SKU's own normal daily rate on its non-stockout days in the same window. A pair that was out of stock the entire window is excluded: there's no in-stock data to estimate a normal rate from."
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => `${row.warehouse_id}-${row.sku_id}`}
                sort={null}
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No lost sales estimated"
                emptyDescription="No warehouse/SKU pair had both a stockout and in-stock demand data in this window."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU or product…"
                        canReset={
                            search !== '' ||
                            warehouseId !== '' ||
                            skuId !== '' ||
                            lookbackDays !== '30'
                        }
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            setSkuId('');
                            setLookbackDays('30');
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                sku_id: undefined,
                                lookback_days: '30',
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

                        <select
                            className="form-select"
                            value={lookbackDays}
                            onChange={(event) => {
                                setLookbackDays(event.target.value);
                                reload({
                                    lookback_days: event.target.value,
                                    page: 1,
                                });
                            }}
                            aria-label="Lookback window"
                        >
                            <option value="7">Last 7 days</option>
                            <option value="30">Last 30 days</option>
                            <option value="90">Last 90 days</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
