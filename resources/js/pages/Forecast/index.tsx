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
import { index, scoreAccuracy } from '@/routes/forecast';
import type { Option, SkuOption } from '@/types/catalog';
import type { Forecast } from '@/types/forecasting';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sku_id?: string;
};

type Props = {
    forecasts: Paginated<Forecast>;
    filters: Filters;
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

export default function ForecastIndex({
    forecasts,
    filters,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const [scoring, setScoring] = useState(false);
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                warehouse_id: warehouseId || undefined,
                sku_id: skuId || undefined,
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

    function runScoreAccuracy() {
        setScoring(true);
        router.post(
            scoreAccuracy.url(),
            {},
            { preserveScroll: true, onFinish: () => setScoring(false) },
        );
    }

    const columns: Column<Forecast>[] = [
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
            key: 'forecast_date',
            header: 'For',
            cell: (row) => `${row.forecast_date} (${row.horizon_days}d)`,
        },
        {
            key: 'predicted_qty',
            header: 'Predicted',
            numeric: true,
            cell: (row) =>
                `${row.predicted_qty.toFixed(0)} (${row.lower_qty.toFixed(0)}–${row.upper_qty.toFixed(0)})`,
        },
        {
            key: 'confidence_score',
            header: 'Confidence',
            numeric: true,
            cell: (row) => `${row.confidence_score}%`,
        },
        {
            key: 'forecast_source',
            header: 'Source',
            cell: (row) => (
                <div>
                    <StatusBadge
                        tone="secondary"
                        label={row.forecast_source_label}
                    />
                    {row.model_version && (
                        <p className="app-text-muted small mb-0 mt-1">
                            {row.model_version}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'accuracy',
            header: 'Actual vs. predicted',
            cell: (row) =>
                row.accuracy ? (
                    <StatusBadge
                        tone={
                            row.accuracy.percentage_error !== null &&
                            row.accuracy.percentage_error <= 20
                                ? 'success'
                                : 'warning'
                        }
                        label={`${row.accuracy.actual_qty.toFixed(0)} actual (${row.accuracy.percentage_error?.toFixed(0) ?? '—'}% error)`}
                    />
                ) : (
                    <span className="app-text-muted">Not due yet</span>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Forecasting"
                title="Forecasts"
                description="Predicted demand from the ML service — one of two baseline algorithms, chosen per SKU from real backtested accuracy history. See Forecast runs for how these were produced."
                actions={
                    <button
                        type="button"
                        className="btn btn-surface"
                        disabled={scoring}
                        onClick={runScoreAccuracy}
                    >
                        {scoring ? (
                            <Spinner size="sm" />
                        ) : (
                            <RefreshCw aria-hidden="true" />
                        )}
                        Score accuracy
                    </button>
                }
            />

            <DataTable
                columns={columns}
                rows={forecasts.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={forecasts}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No forecasts yet"
                emptyDescription="Queue a forecast run to generate predictions."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU…"
                        canReset={
                            search !== '' || warehouseId !== '' || skuId !== ''
                        }
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            setSkuId('');
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                sku_id: undefined,
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
                    </TableFilters>
                }
            />
        </>
    );
}
