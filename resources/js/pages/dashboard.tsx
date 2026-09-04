import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    BarChart3,
    Boxes,
    CloudDownload,
    Coins,
    ListChecks,
    PackageSearch,
    PackageX,
    ShoppingCart,
    Split,
    TrendingDown,
    TrendingUp,
    Warehouse,
} from 'lucide-react';
import BarChart from '@/components/charts/bar-chart';
import type { BarDatum } from '@/components/charts/bar-chart';
import TrendChart from '@/components/charts/trend-chart';
import type { TrendSeries } from '@/components/charts/trend-chart';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import ShortcutCard from '@/components/shortcut-card';
import StatCard from '@/components/stat-card';
import StatusBadge from '@/components/status-badge';
import {
    formatCompact,
    formatMoney,
    formatMoneyCompact,
    formatNumber,
} from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as buyabansSyncIndex } from '@/routes/buyabans-sync';
import { lostSales as demandInsightsLostSales } from '@/routes/demand-insights';
import { index as forecastIndex } from '@/routes/forecast';
import { index as inventoryAnalyticsIndex } from '@/routes/inventory-analytics';
import {
    centralAllocation as inventoryRecommendationCentralAllocation,
    index as inventoryRecommendationIndex,
} from '@/routes/inventory-recommendation';

type Metric = {
    value: number;
    delta?: number;
    trend?: number[];
};

type Trend = {
    labels: string[];
    series: TrendSeries[];
};

type LatestRun = {
    finishedAt: string | null;
    status: string;
    horizonDays: number;
    forecasts: number;
};

type Props = {
    /** Currency code for every money figure. Never converted, only labelled. */
    currency?: string;
    /** The window the headline figures describe, so the page can say it. */
    window?: { from: string; to: string; days: number } | null;
    /** Absent metrics render as an em dash rather than a fabricated figure. */
    metrics?: {
        revenue?: Metric | null;
        unitsSold?: Metric | null;
        orders?: Metric | null;
        averageOrderValue?: Metric | null;
        stockValue?: Metric | null;
        stockCoverage?: Metric | null;
        outOfStockLines?: Metric | null;
        reorderAlerts?: Metric | null;
        skusTracked?: Metric | null;
        forecastAccuracy?: Metric | null;
    };
    demandTrend?: Trend;
    revenueTrend?: Trend;
    topMovers?: BarDatum[];
    revenueByCategory?: BarDatum[];
    demandByLocation?: BarDatum[];
    coverHealth?: { items: BarDatum[]; skus: number } | null;
    forecastStatus?: { latestRun: LatestRun | null };
};

const NO_VALUE = '—';

function metricValue(metric: Metric | null | undefined, suffix = ''): string {
    if (!metric) {
        return NO_VALUE;
    }

    return `${formatCompact(metric.value)}${suffix}`;
}

function moneyValue(
    metric: Metric | null | undefined,
    currency: string,
    exact = false,
): string {
    if (!metric) {
        return NO_VALUE;
    }

    return exact
        ? formatMoney(metric.value, currency)
        : formatMoneyCompact(metric.value, currency);
}

export default function Dashboard({
    currency = 'LKR',
    window: period,
    metrics,
    demandTrend,
    revenueTrend,
    topMovers,
    revenueByCategory,
    demandByLocation,
    coverHealth,
    forecastStatus,
}: Props) {
    const run = forecastStatus?.latestRun ?? null;

    // Every headline figure is measured to the last day demand was synced for,
    // not to today. Saying which days those are is the difference between a
    // number and a number somebody can check.
    const windowLabel = period
        ? `${period.days} days to ${period.to}`
        : 'the latest synced data';

    return (
        <>
            <Head title="Dashboard" />

            <div className="app-stack">
                <PageHeader
                    eyebrow="Overview"
                    title="Dashboard"
                    description={`Trade, stock position and forecast health over the ${windowLabel}.`}
                />

                <section>
                    <div className="app-section-header mb-3">
                        <div>
                            <h2 className="app-section-title">Trading</h2>
                            <p className="app-subtitle">
                                {period
                                    ? `${period.from} to ${period.to}. Change compares the last four weeks with the four before them.`
                                    : 'No demand has been synced yet.'}
                            </p>
                        </div>
                    </div>

                    <div className="row g-3">
                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Revenue"
                                value={moneyValue(metrics?.revenue, currency)}
                                icon={Banknote}
                                delta={metrics?.revenue?.delta ?? null}
                                deltaLabel="vs previous 4 weeks"
                                trend={metrics?.revenue?.trend}
                            />
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Units sold"
                                value={metricValue(metrics?.unitsSold)}
                                icon={Boxes}
                                tone="info"
                                delta={metrics?.unitsSold?.delta ?? null}
                                deltaLabel="vs previous 4 weeks"
                                trend={metrics?.unitsSold?.trend}
                            />
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Orders"
                                value={metricValue(metrics?.orders)}
                                icon={ShoppingCart}
                                tone="info"
                                delta={metrics?.orders?.delta ?? null}
                                deltaLabel="vs previous 4 weeks"
                                trend={metrics?.orders?.trend}
                            />
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Average order value"
                                value={moneyValue(
                                    metrics?.averageOrderValue,
                                    currency,
                                    true,
                                )}
                                icon={Coins}
                                tone="success"
                                delta={
                                    metrics?.averageOrderValue?.delta ?? null
                                }
                                deltaLabel="vs previous 4 weeks"
                                trend={metrics?.averageOrderValue?.trend}
                            />
                        </div>
                    </div>
                </section>

                <section>
                    <div className="app-section-header mb-3">
                        <div>
                            <h2 className="app-section-title">
                                Stock position
                            </h2>
                            <p className="app-subtitle">
                                What is on hand right now, and what needs a
                                decision.
                            </p>
                        </div>
                    </div>

                    <div className="row g-3">
                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Stock on hand (retail)"
                                value={moneyValue(
                                    metrics?.stockValue,
                                    currency,
                                )}
                                icon={Warehouse}
                            />
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Days of cover"
                                value={metricValue(metrics?.stockCoverage)}
                                icon={PackageSearch}
                                tone="info"
                            />
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Lines out of stock"
                                value={metricValue(metrics?.outOfStockLines)}
                                icon={PackageX}
                                tone="danger"
                                upIsGood={false}
                            />
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <StatCard
                                label="Reorder alerts"
                                value={metricValue(metrics?.reorderAlerts)}
                                icon={AlertTriangle}
                                tone="warning"
                                upIsGood={false}
                            />
                        </div>
                    </div>
                </section>

                {/* Units and money are different scales, so they get two charts
                    rather than one with two y-axes — with two axes the drawing
                    can imply any relationship you like by choosing them. */}
                <div className="row g-3">
                    <div className="col-xl-6">
                        <SectionCard
                            title="Units sold per week"
                            subtitle="Last 12 weeks, from synced demand."
                            className="h-100"
                        >
                            <TrendChart
                                labels={demandTrend?.labels ?? []}
                                series={demandTrend?.series ?? []}
                                emptyTitle="No demand history yet"
                                emptyDescription="Run a BuyAbans sync to load demand history."
                            />
                        </SectionCard>
                    </div>

                    <div className="col-xl-6">
                        <SectionCard
                            title="Revenue per week"
                            subtitle={`Last 12 weeks, in ${currency}.`}
                            className="h-100"
                        >
                            <TrendChart
                                labels={revenueTrend?.labels ?? []}
                                series={revenueTrend?.series ?? []}
                                emptyTitle="No revenue history yet"
                                emptyDescription="Run a BuyAbans sync to load demand history."
                            />
                        </SectionCard>
                    </div>
                </div>

                <div className="row g-3">
                    <div className="col-xl-6">
                        <SectionCard
                            title="Where the money comes from"
                            subtitle={`Revenue by category, ${currency}, ${windowLabel}.`}
                            className="h-100"
                        >
                            <BarChart
                                items={revenueByCategory ?? []}
                                horizontal
                                height={280}
                                emptyTitle="No category revenue yet"
                                emptyDescription="Categories appear once demand has been synced."
                            />
                        </SectionCard>
                    </div>

                    <div className="col-xl-6">
                        <SectionCard
                            title="Where it sells"
                            subtitle={`Revenue by warehouse, ${currency}, ${windowLabel}.`}
                            className="h-100"
                        >
                            <BarChart
                                items={demandByLocation ?? []}
                                horizontal
                                height={280}
                                emptyTitle="No location split available"
                                emptyDescription="Demand is only split by warehouse at the warehouse grain."
                            />
                        </SectionCard>
                    </div>
                </div>

                <div className="row g-3">
                    <div className="col-xl-6">
                        <SectionCard
                            title="Top movers"
                            subtitle={`Units sold, ${windowLabel}.`}
                            className="h-100"
                        >
                            <BarChart
                                items={topMovers ?? []}
                                horizontal
                                height={280}
                                emptyTitle="Nothing to compare yet"
                                emptyDescription="Movement ranks appear once demand has been synced."
                            />
                        </SectionCard>
                    </div>

                    <div className="col-xl-6">
                        <SectionCard
                            title="How long stock will last"
                            subtitle={
                                coverHealth
                                    ? `Products by days of cover, across the ${formatNumber(coverHealth.skus)} that sold in this window.`
                                    : 'Products by days of cover.'
                            }
                            className="h-100"
                        >
                            <BarChart
                                items={coverHealth?.items ?? []}
                                horizontal
                                height={240}
                                emptyTitle="No cover to measure yet"
                                emptyDescription="Cover needs both stock on hand and recent sales."
                            />
                        </SectionCard>
                    </div>
                </div>

                <SectionCard
                    title="Forecast health"
                    subtitle="How fresh the predictions on this system are, and how far they reach."
                >
                    <div className="row g-3">
                        <div className="col-sm-6 col-xl-3">
                            <p className="app-subtitle mb-1">Last run</p>
                            <p className="fw-semibold mb-0">
                                {run?.finishedAt ?? NO_VALUE}
                            </p>
                            {run && (
                                <StatusBadge
                                    tone={
                                        run.status === 'completed'
                                            ? 'success'
                                            : 'warning'
                                    }
                                    label={run.status}
                                    className="mt-2"
                                />
                            )}
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <p className="app-subtitle mb-1">
                                Predictions in that run
                            </p>
                            <p className="fw-semibold mb-0">
                                {run
                                    ? `${formatNumber(run.forecasts)} over ${run.horizonDays} days`
                                    : NO_VALUE}
                            </p>
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <p className="app-subtitle mb-1">
                                Products being forecast
                            </p>
                            <p className="fw-semibold mb-0">
                                {metricValue(metrics?.skusTracked)}
                            </p>
                        </div>

                        <div className="col-sm-6 col-xl-3">
                            <p className="app-subtitle mb-1">Accuracy so far</p>
                            <p className="fw-semibold mb-0">
                                {metricValue(metrics?.forecastAccuracy, '%')}
                            </p>
                            {/* An em dash here is the honest answer, not a
                                missing feature: accuracy cannot exist until a
                                forecast's horizon has elapsed and been scored. */}
                            {!metrics?.forecastAccuracy && (
                                <p className="app-subtitle mb-0">
                                    No forecast has finished its window and been
                                    scored yet.
                                </p>
                            )}
                        </div>
                    </div>

                    <Link
                        href={forecastIndex()}
                        className="btn btn-surface mt-3"
                    >
                        See the forecasts
                    </Link>
                </SectionCard>

                <section>
                    <div className="app-section-header mb-3">
                        <div>
                            <h2 className="app-section-title">
                                Decisions waiting for you
                            </h2>
                            <p className="app-subtitle">
                                The places this data is meant to be acted on.
                            </p>
                        </div>
                    </div>

                    <div className="app-grid-cards app-grid-cards--wide">
                        <ShortcutCard
                            href={inventoryRecommendationIndex()}
                            icon={ListChecks}
                            title="Purchase recommendations"
                            description="What to reorder, and how much"
                        />
                        <ShortcutCard
                            href={inventoryRecommendationCentralAllocation()}
                            icon={Split}
                            title="Central allocation"
                            description="How to split incoming stock across warehouses"
                        />
                        <ShortcutCard
                            href={forecastIndex()}
                            icon={TrendingUp}
                            title="Forecasts"
                            description="What each product is expected to sell"
                        />
                        <ShortcutCard
                            href={demandInsightsLostSales()}
                            icon={TrendingDown}
                            title="Lost sales"
                            description="Demand that stockouts cost you"
                        />
                        <ShortcutCard
                            href={inventoryAnalyticsIndex()}
                            icon={BarChart3}
                            title="Inventory analytics"
                            description="Ageing, turnover and slow movers"
                        />
                        <ShortcutCard
                            href={buyabansSyncIndex()}
                            icon={CloudDownload}
                            title="BuyAbans sync"
                            description="Pull the latest catalogue and sales"
                        />
                    </div>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
