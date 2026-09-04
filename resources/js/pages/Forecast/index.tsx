import { router } from '@inertiajs/react';
import {
    Boxes,
    CalendarRange,
    Gauge,
    RefreshCw,
    TrendingUp,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import BarChart from '@/components/charts/bar-chart';
import TrendChart from '@/components/charts/trend-chart';
import type { TrendBand, TrendSeries } from '@/components/charts/trend-chart';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatCard from '@/components/stat-card';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index, scoreAccuracy } from '@/routes/forecast';
import type { Option, SkuOption } from '@/types/catalog';
import type { Forecast, ForecastOverview } from '@/types/forecasting';
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
    overview: ForecastOverview;
};

const NUMBER = new Intl.NumberFormat();

function units(value: number): string {
    return NUMBER.format(Math.round(value));
}

/**
 * A confidence score as a word. "42%" means nothing without knowing the scale;
 * "Medium" does, and the number stays available beside it.
 */
function confidenceBand(score: number): {
    label: string;
    tone: 'success' | 'warning' | 'danger';
} {
    if (score >= 70) {
        return { label: 'High', tone: 'success' };
    }

    return score >= 40
        ? { label: 'Medium', tone: 'warning' }
        : { label: 'Low', tone: 'danger' };
}

export default function ForecastIndex({
    forecasts,
    filters,
    warehouseOptions,
    skuOptions,
    overview,
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

    const summary = overview.summary;
    const change = summary?.changePercent ?? null;

    const columns: Column<Forecast>[] = [
        {
            key: 'sku',
            header: 'Product',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">
                        {row.sku?.product_name ?? row.sku?.sku ?? '—'}
                    </p>
                    <p className="app-text-muted small mb-0">
                        {row.sku?.sku ?? '—'}
                        {row.warehouse?.name ? ` · ${row.warehouse.name}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'predicted_qty',
            header: 'Expected to sell',
            numeric: true,
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">
                        {units(row.predicted_qty)} units
                    </p>
                    <p className="app-text-muted small mb-0">
                        likely {units(row.lower_qty)}–{units(row.upper_qty)}
                    </p>
                </div>
            ),
        },
        {
            key: 'forecast_date',
            header: 'Over',
            cell: (row) => (
                <div>
                    <p className="mb-0">the next {row.horizon_days} days</p>
                    <p className="app-text-muted small mb-0">
                        from {row.forecast_date}
                    </p>
                </div>
            ),
        },
        {
            key: 'confidence_score',
            header: 'How sure',
            cell: (row) => {
                const band = confidenceBand(row.confidence_score);

                return (
                    <StatusBadge
                        tone={band.tone}
                        label={`${band.label} · ${row.confidence_score}%`}
                    />
                );
            },
        },
        {
            key: 'forecast_source',
            header: 'Based on',
            cell: (row) => (
                <span className="app-text-muted small">
                    {row.forecast_source_explanation ??
                        row.forecast_source_label}
                </span>
            ),
        },
        {
            key: 'accuracy',
            header: 'How it turned out',
            cell: (row) =>
                row.accuracy ? (
                    <StatusBadge
                        tone={
                            row.accuracy.percentage_error !== null &&
                            row.accuracy.percentage_error <= 20
                                ? 'success'
                                : 'warning'
                        }
                        label={`Sold ${units(row.accuracy.actual_qty)} · ${row.accuracy.percentage_error?.toFixed(0) ?? '—'}% out`}
                    />
                ) : (
                    <span className="app-text-muted small">
                        Still in the future
                    </span>
                ),
        },
    ];

    const canReset = search !== '' || warehouseId !== '' || skuId !== '';

    const chartSeries: TrendSeries[] = overview.chart.series;
    const chartBand: TrendBand | undefined = overview.chart.band;

    return (
        <div className="app-stack">
            <PageHeader
                eyebrow="Forecasting"
                title="What we expect to sell"
                description="Recent sales and the demand predicted for the weeks ahead, product by product."
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
                        Check past forecasts
                    </button>
                }
            />

            {summary && (
                <SectionCard accent>
                    <p className="app-forecast-headline mb-2">
                        Over the next{' '}
                        <strong>{summary.horizonDays} days</strong> we expect to
                        sell about{' '}
                        <strong>{units(summary.expectedUnits)} units</strong>{' '}
                        across <strong>{summary.products} products</strong>.
                    </p>
                    <p className="app-text-muted mb-0">
                        Realistically somewhere between{' '}
                        {units(summary.rangeLow)} and {units(summary.rangeHigh)}{' '}
                        units. The last {summary.horizonDays} days sold{' '}
                        {units(summary.previousUnits)}.{' '}
                        {summary.confidence.explanation}
                    </p>
                </SectionCard>
            )}

            <div className="row g-3">
                <div className="col-sm-6 col-xl-3">
                    <StatCard
                        label="Expected units"
                        value={summary ? units(summary.expectedUnits) : '—'}
                        icon={TrendingUp}
                        tone="brand"
                        delta={change}
                        deltaLabel={
                            change !== null
                                ? `vs last ${summary?.horizonDays ?? 30} days`
                                : undefined
                        }
                    />
                </div>
                <div className="col-sm-6 col-xl-3">
                    <StatCard
                        label="Products covered"
                        value={summary ? String(summary.products) : '—'}
                        icon={Boxes}
                        tone="info"
                    />
                </div>
                <div className="col-sm-6 col-xl-3">
                    <StatCard
                        label="Confidence"
                        value={
                            summary
                                ? `${summary.confidence.label} · ${summary.confidence.score}%`
                                : '—'
                        }
                        icon={Gauge}
                        tone={
                            summary?.confidence.tone === 'success'
                                ? 'success'
                                : summary?.confidence.tone === 'warning'
                                  ? 'warning'
                                  : 'danger'
                        }
                    />
                </div>
                <div className="col-sm-6 col-xl-3">
                    <StatCard
                        label="Forecast period"
                        value={summary ? `${summary.horizonDays} days` : '—'}
                        icon={CalendarRange}
                        tone="warning"
                    />
                </div>
            </div>

            <div className="row g-3">
                <div className="col-xl-8">
                    <SectionCard
                        title="Sales so far, and what comes next"
                        subtitle="Solid line is what actually sold each week. The shaded band is the range the forecast considers likely."
                        className="h-100"
                    >
                        <TrendChart
                            labels={overview.chart.labels}
                            series={chartSeries}
                            band={chartBand}
                            height={280}
                            emptyTitle="No forecast yet"
                            emptyDescription="Start a forecast run to see expected demand here."
                        />
                    </SectionCard>
                </div>

                <div className="col-xl-4">
                    <SectionCard
                        title="Expected to sell most"
                        subtitle="Units predicted over the forecast period."
                        className="h-100"
                    >
                        <BarChart
                            items={overview.topProducts}
                            horizontal
                            height={280}
                            emptyTitle="Nothing to rank yet"
                            emptyDescription="Ranks appear once a forecast run has completed."
                        />
                    </SectionCard>
                </div>
            </div>

            {summary && summary.basis.length > 0 && (
                <SectionCard
                    title="How these numbers were worked out"
                    subtitle="Every forecast is based on sales history — its own where there is enough, similar products where there is not."
                >
                    <div className="row g-3">
                        {summary.basis.map((item) => (
                            <div key={item.label} className="col-md-6 col-xl-4">
                                <div className="app-basis-card">
                                    <div className="d-flex align-items-center justify-content-between gap-2 mb-1">
                                        <span className="fw-semibold">
                                            {item.label}
                                        </span>
                                        <StatusBadge
                                            tone="secondary"
                                            label={`${item.share}%`}
                                        />
                                    </div>
                                    <p className="app-text-muted small mb-0">
                                        {item.explanation}
                                    </p>
                                    <p className="app-text-soft small mb-0 mt-1">
                                        {units(item.count)} forecasts
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </SectionCard>
            )}

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
                emptyDescription="Start a forecast run to generate predictions."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by product or SKU…"
                        canReset={canReset}
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
        </div>
    );
}
