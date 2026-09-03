import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Boxes,
    PackageSearch,
    Settings2,
    ShieldCheck,
    TrendingUp,
} from 'lucide-react';
import BarChart from '@/components/charts/bar-chart';
import type { BarDatum } from '@/components/charts/bar-chart';
import TrendChart from '@/components/charts/trend-chart';
import type { TrendSeries } from '@/components/charts/trend-chart';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import ShortcutCard from '@/components/shortcut-card';
import StatCard from '@/components/stat-card';
import { formatCompact } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

type Metric = {
    value: number;
    delta?: number;
    trend?: number[];
};

type Props = {
    /** Supplied once a forecasting module publishes its figures. */
    metrics?: {
        skusTracked?: Metric;
        forecastAccuracy?: Metric;
        reorderAlerts?: Metric;
        stockCoverage?: Metric;
    };
    demandTrend?: {
        labels: string[];
        series: TrendSeries[];
    };
    topMovers?: BarDatum[];
};

const NO_VALUE = '—';

function metricValue(metric: Metric | undefined, suffix = ''): string {
    if (!metric) {
        return NO_VALUE;
    }

    return `${formatCompact(metric.value)}${suffix}`;
}

export default function Dashboard({ metrics, demandTrend, topMovers }: Props) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="app-stack">
                <PageHeader
                    eyebrow="Overview"
                    title="Dashboard"
                    description="Demand, coverage and reorder signals across your catalogue."
                />

                <div className="row g-3">
                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="SKUs tracked"
                            value={metricValue(metrics?.skusTracked)}
                            icon={Boxes}
                            delta={metrics?.skusTracked?.delta ?? null}
                            deltaLabel={
                                metrics?.skusTracked
                                    ? 'vs last month'
                                    : undefined
                            }
                            trend={metrics?.skusTracked?.trend}
                        />
                    </div>

                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Forecast accuracy"
                            value={metricValue(metrics?.forecastAccuracy, '%')}
                            icon={TrendingUp}
                            tone="success"
                            delta={metrics?.forecastAccuracy?.delta ?? null}
                            deltaLabel={
                                metrics?.forecastAccuracy
                                    ? 'vs last month'
                                    : undefined
                            }
                            trend={metrics?.forecastAccuracy?.trend}
                        />
                    </div>

                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Reorder alerts"
                            value={metricValue(metrics?.reorderAlerts)}
                            icon={AlertTriangle}
                            tone="warning"
                            upIsGood={false}
                            delta={metrics?.reorderAlerts?.delta ?? null}
                            deltaLabel={
                                metrics?.reorderAlerts
                                    ? 'vs last week'
                                    : undefined
                            }
                            trend={metrics?.reorderAlerts?.trend}
                        />
                    </div>

                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Days of cover"
                            value={metricValue(metrics?.stockCoverage)}
                            icon={PackageSearch}
                            tone="info"
                            delta={metrics?.stockCoverage?.delta ?? null}
                            deltaLabel={
                                metrics?.stockCoverage
                                    ? 'vs last week'
                                    : undefined
                            }
                            trend={metrics?.stockCoverage?.trend}
                        />
                    </div>
                </div>

                <div className="row g-3">
                    <div className="col-xl-8">
                        <SectionCard
                            title="Demand trend"
                            subtitle="Units forecast against units sold."
                            className="h-100"
                        >
                            <TrendChart
                                labels={demandTrend?.labels ?? []}
                                series={demandTrend?.series ?? []}
                                emptyTitle="No demand history yet"
                                emptyDescription="Once a forecasting module publishes its figures, the trend appears here."
                            />
                        </SectionCard>
                    </div>

                    <div className="col-xl-4">
                        <SectionCard
                            title="Top movers"
                            subtitle="Highest volume this period."
                            className="h-100"
                        >
                            <BarChart
                                items={topMovers ?? []}
                                height={260}
                                emptyTitle="Nothing to compare yet"
                                emptyDescription="Movement ranks appear once stock records exist."
                            />
                        </SectionCard>
                    </div>
                </div>

                <section>
                    <div className="app-section-header mb-3">
                        <div>
                            <h2 className="app-section-title">Quick actions</h2>
                            <p className="app-subtitle">
                                Jump straight to the things you do most.
                            </p>
                        </div>
                    </div>

                    <div className="app-grid-cards app-grid-cards--wide">
                        <ShortcutCard
                            href={editProfile()}
                            icon={Settings2}
                            title="Profile settings"
                            description="Update your name and email address"
                        />
                        <ShortcutCard
                            href={editSecurity()}
                            icon={ShieldCheck}
                            title="Security"
                            description="Passwords, two-factor and passkeys"
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
