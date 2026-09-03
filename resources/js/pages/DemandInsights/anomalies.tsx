import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { anomalies as anomaliesRoute } from '@/routes/demand-insights';
import type { DemandAnomalyRow } from '@/types/advanced-intelligence';
import type { Option, SkuOption } from '@/types/catalog';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sku_id?: string;
    lookback_days?: string;
};

type Props = {
    rows: Paginated<DemandAnomalyRow>;
    filters: Filters;
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

function zScoreTone(zScore: number): StatusTone {
    return Math.abs(zScore) >= 3 ? 'danger' : 'warning';
}

export default function DemandInsightsAnomalies({
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
            anomaliesRoute.url(),
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

    const columns: Column<DemandAnomalyRow>[] = [
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
            key: 'snapshot_date',
            header: 'Date',
            cell: (row) => row.snapshot_date,
        },
        {
            key: 'actual_qty',
            header: 'Actual',
            numeric: true,
            cell: (row) => row.actual_qty,
        },
        {
            key: 'expected_qty',
            header: 'Expected',
            numeric: true,
            cell: (row) => row.expected_qty.toFixed(1),
        },
        {
            key: 'z_score',
            header: 'Deviation',
            numeric: true,
            cell: (row) => (
                <StatusBadge
                    tone={zScoreTone(row.z_score)}
                    label={`${row.z_score > 0 ? '+' : ''}${row.z_score.toFixed(2)}σ`}
                />
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Demand anomalies"
                description="Days where a SKU's actual demand was at least 2 standard deviations from its own mean over the window — a real statistical outlier, not a threshold calibrated against known causes. A pair needs at least two days of real variability before it can have a std-dev at all."
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) =>
                    `${row.warehouse_id}-${row.sku_id}-${row.snapshot_date}`
                }
                sort={null}
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No anomalies detected"
                emptyDescription="No day in this window deviated more than 2 standard deviations from its SKU's own mean."
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
