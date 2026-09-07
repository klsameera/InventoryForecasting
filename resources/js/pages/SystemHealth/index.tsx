import { Head } from '@inertiajs/react';
import { Brain, HardDrive, ListChecks, Plug, RefreshCw } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatCard from '@/components/stat-card';
import StatusBadge from '@/components/status-badge';
import { formatNumber } from '@/lib/utils';
import { index } from '@/routes/system-health';
import type { StatusTone } from '@/types/ui';

type Dependency = {
    name: string;
    reachable: boolean;
    detail: string;
    latencyMs: number | null;
    configured: boolean;
};

type Algorithm = {
    name: string;
    trained: boolean;
    available: boolean;
    trainedThrough: string | null;
    maxHorizonDays: number | null;
    minHistoryDays: number | null;
    behindDemandDays: number | null;
    isPreferred: boolean;
};

type Score = {
    name: string;
    wape: number | null;
    mae: number | null;
    bias: number | null;
};

type Grain = {
    grain: string;
    rows: number;
    skus: number;
    firstDay: string | null;
    lastDay: string | null;
    unitsInWindow: number;
    daysBehind: number | null;
};

type SyncRun = {
    stage: string;
    status: string;
    grain: string | null;
    fetched: number;
    written: number;
    finishedAt: string | null;
    hoursAgo: number | null;
    stale: boolean;
    message: string | null;
};

type Props = {
    generatedAt: string;
    runtime: Record<string, string | boolean>;
    dependencies: Dependency[];
    models: {
        reachable: boolean;
        message: string | null;
        preferred: string;
        algorithms: Algorithm[];
    };
    training: {
        holdoutDays: number | null;
        maxEpochs: number | null;
        tftLoss: string | null;
        writtenAt: string | null;
        models: (Score & {
            actualTotal: number | null;
            predictedTotal: number | null;
        })[];
    } | null;
    evaluation: {
        horizonDays: number | null;
        windows: number;
        best: string | null;
        writtenAt: string | null;
        pooled: Score[];
    } | null;
    data: { grains: Grain[]; reconcileDays: number; unitsAgree: boolean };
    syncRuns: SyncRun[];
    pipeline: {
        latestRun: {
            id: number;
            status: string;
            horizonDays: number;
            forecasts: number;
            finishedAt: string | null;
            durationSeconds: number | null;
            error: string | null;
        } | null;
        runsByStatus: Record<string, number>;
        scoredForecasts: number;
        openRecommendations: number;
    };
    queue: {
        driver: string;
        pending: number;
        failed: number;
        oldestPendingMinutes: number | null;
    };
    storage: {
        driver: string;
        tables: { name: string; rows: number; megabytes: number }[];
    } | null;
};

const NO_VALUE = '—';

function statusTone(status: string): StatusTone {
    const value = status.toLowerCase();

    if (['completed', 'success', 'succeeded'].includes(value)) {
        return 'success';
    }

    if (['failed', 'error'].includes(value)) {
        return 'danger';
    }

    if (['processing', 'running', 'pending', 'queued'].includes(value)) {
        return 'warning';
    }

    return 'secondary';
}

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return NO_VALUE;
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export default function SystemHealthIndex({
    generatedAt,
    runtime,
    dependencies,
    models,
    training,
    evaluation,
    data,
    syncRuns,
    pipeline,
    queue,
    storage,
}: Props) {
    const down = dependencies.filter((item) => !item.reachable).length;
    const trackedGrain = data.grains.find((grain) => grain.rows > 0) ?? null;

    return (
        <>
            <Head title="System health" />

            <div className="app-stack">
                <PageHeader
                    eyebrow="Operations"
                    title="System health"
                    description={`Everything below was measured when this page loaded, at ${generatedAt}. Nothing here is cached.`}
                />

                <div className="row g-3">
                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Dependencies reachable"
                            value={`${dependencies.length - down} / ${dependencies.length}`}
                            icon={Plug}
                            tone={down === 0 ? 'success' : 'danger'}
                        />
                    </div>

                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Demand is behind by"
                            value={
                                trackedGrain?.daysBehind === null ||
                                trackedGrain === null
                                    ? NO_VALUE
                                    : `${trackedGrain.daysBehind} days`
                            }
                            icon={RefreshCw}
                            tone={
                                (trackedGrain?.daysBehind ?? 0) > 2
                                    ? 'warning'
                                    : 'success'
                            }
                            upIsGood={false}
                        />
                    </div>

                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Jobs waiting"
                            value={formatNumber(queue.pending)}
                            icon={ListChecks}
                            tone={queue.failed > 0 ? 'danger' : 'info'}
                        />
                    </div>

                    <div className="col-sm-6 col-xl-3">
                        <StatCard
                            label="Serving algorithm"
                            value={models.preferred}
                            icon={Brain}
                            tone="brand"
                        />
                    </div>
                </div>

                <SectionCard
                    title="Dependencies"
                    subtitle="Probed with a GET when this page loaded. A slow answer is still an answer — latency is shown so 'up but slow' stays visible."
                    flush
                >
                    <div className="app-table-wrap">
                        <table className="app-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>State</th>
                                    <th className="app-table__numeric">
                                        Latency
                                    </th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                {dependencies.map((item) => (
                                    <tr key={item.name}>
                                        <td className="fw-semibold">
                                            {item.name}
                                        </td>
                                        <td>
                                            <StatusBadge
                                                tone={
                                                    item.reachable
                                                        ? 'success'
                                                        : 'danger'
                                                }
                                                label={
                                                    item.reachable
                                                        ? 'Reachable'
                                                        : 'Unreachable'
                                                }
                                            />
                                            {!item.configured && (
                                                <StatusBadge
                                                    tone="warning"
                                                    label="Not configured"
                                                    className="ms-2"
                                                />
                                            )}
                                        </td>
                                        <td className="app-table__numeric">
                                            {item.latencyMs === null
                                                ? NO_VALUE
                                                : `${formatNumber(item.latencyMs)} ms`}
                                        </td>
                                        <td className="app-text-muted small">
                                            {item.detail}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard
                    title="Forecasting models"
                    subtitle="What the ML service will serve, and how far each trained model is behind the data it would be asked to predict."
                    flush
                >
                    {models.reachable ? (
                        <div className="app-table-wrap">
                            <table className="app-table">
                                <thead>
                                    <tr>
                                        <th>Algorithm</th>
                                        <th>State</th>
                                        <th>Trained through</th>
                                        <th className="app-table__numeric">
                                            Behind demand
                                        </th>
                                        <th className="app-table__numeric">
                                            Max horizon
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {models.algorithms.map((algorithm) => (
                                        <tr key={algorithm.name}>
                                            <td className="fw-semibold">
                                                {algorithm.name}
                                                {algorithm.isPreferred && (
                                                    <StatusBadge
                                                        tone="primary"
                                                        label="Serving"
                                                        className="ms-2"
                                                    />
                                                )}
                                            </td>
                                            <td>
                                                <StatusBadge
                                                    tone={
                                                        algorithm.available
                                                            ? 'success'
                                                            : 'secondary'
                                                    }
                                                    label={
                                                        algorithm.trained
                                                            ? 'Trained'
                                                            : 'Computed on request'
                                                    }
                                                />
                                            </td>
                                            <td>
                                                {algorithm.trainedThrough ??
                                                    NO_VALUE}
                                            </td>
                                            <td className="app-table__numeric">
                                                {algorithm.behindDemandDays ===
                                                null ? (
                                                    NO_VALUE
                                                ) : (
                                                    <StatusBadge
                                                        tone={
                                                            algorithm.behindDemandDays >
                                                            30
                                                                ? 'danger'
                                                                : 'warning'
                                                        }
                                                        label={`${algorithm.behindDemandDays} days`}
                                                    />
                                                )}
                                            </td>
                                            <td className="app-table__numeric">
                                                {algorithm.maxHorizonDays ===
                                                null
                                                    ? NO_VALUE
                                                    : `${algorithm.maxHorizonDays} days`}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-4">
                            <EmptyState
                                compact
                                title="The ML service did not answer"
                                description={
                                    models.message ??
                                    'Nothing could be read about the models.'
                                }
                            />
                        </div>
                    )}
                </SectionCard>

                <div className="row g-3">
                    <div className="col-xl-6">
                        <SectionCard
                            title="Last training run"
                            subtitle={
                                training
                                    ? `Each model against its own held-out data${training.holdoutDays ? `, ${training.holdoutDays} days held back` : ''}. Written ${training.writtenAt ?? 'at an unknown time'}.`
                                    : 'No training artefact on disk.'
                            }
                            className="h-100"
                            flush
                        >
                            {training && training.models.length > 0 ? (
                                <div className="app-table-wrap">
                                    <table className="app-table">
                                        <thead>
                                            <tr>
                                                <th>Model</th>
                                                <th className="app-table__numeric">
                                                    WAPE
                                                </th>
                                                <th className="app-table__numeric">
                                                    MAE
                                                </th>
                                                <th className="app-table__numeric">
                                                    Bias
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {training.models.map((model) => (
                                                <tr key={model.name}>
                                                    <td className="fw-semibold">
                                                        {model.name}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {model.wape ?? NO_VALUE}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {model.mae ?? NO_VALUE}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {model.bias === null
                                                            ? NO_VALUE
                                                            : `${model.bias > 0 ? '+' : ''}${model.bias}`}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="p-4">
                                    <EmptyState
                                        compact
                                        title="Nothing trained yet"
                                        description="Run app:train-forecast-model to produce a training summary."
                                    />
                                </div>
                            )}
                        </SectionCard>
                    </div>

                    <div className="col-xl-6">
                        <SectionCard
                            title="Last backtest"
                            subtitle={
                                evaluation
                                    ? `Every algorithm on the same held-out windows — the only comparison that says which to serve. ${evaluation.windows} window${evaluation.windows === 1 ? '' : 's'}, written ${evaluation.writtenAt ?? 'at an unknown time'}.`
                                    : 'No backtest on disk.'
                            }
                            className="h-100"
                            flush
                        >
                            {evaluation && evaluation.pooled.length > 0 ? (
                                <div className="app-table-wrap">
                                    <table className="app-table">
                                        <thead>
                                            <tr>
                                                <th>Algorithm</th>
                                                <th className="app-table__numeric">
                                                    WAPE
                                                </th>
                                                <th className="app-table__numeric">
                                                    MAE
                                                </th>
                                                <th className="app-table__numeric">
                                                    Bias
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {evaluation.pooled.map((row) => (
                                                <tr key={row.name}>
                                                    <td className="fw-semibold">
                                                        {row.name}
                                                        {row.name ===
                                                            evaluation.best && (
                                                            <StatusBadge
                                                                tone="success"
                                                                label="Best"
                                                                className="ms-2"
                                                            />
                                                        )}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {row.wape ?? NO_VALUE}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {row.mae ?? NO_VALUE}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {row.bias === null
                                                            ? NO_VALUE
                                                            : `${row.bias > 0 ? '+' : ''}${row.bias}`}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="p-4">
                                    <EmptyState
                                        compact
                                        title="No backtest yet"
                                        description="Run training/evaluate.py to score every algorithm on the same windows."
                                    />
                                </div>
                            )}
                        </SectionCard>
                    </div>
                </div>

                <SectionCard
                    title="Demand data"
                    subtitle={`One row per location grain. The three describe the same sales, so their unit totals must agree exactly — checked over the last ${data.reconcileDays} days.`}
                    actions={
                        <StatusBadge
                            tone={data.unitsAgree ? 'success' : 'danger'}
                            label={
                                data.unitsAgree
                                    ? 'Grains agree'
                                    : 'Grains disagree'
                            }
                        />
                    }
                    flush
                >
                    <div className="app-table-wrap">
                        <table className="app-table">
                            <thead>
                                <tr>
                                    <th>Grain</th>
                                    <th className="app-table__numeric">Rows</th>
                                    <th className="app-table__numeric">SKUs</th>
                                    <th>Covers</th>
                                    <th className="app-table__numeric">
                                        Behind
                                    </th>
                                    <th className="app-table__numeric">
                                        Units ({data.reconcileDays}d)
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.grains.map((grain) => (
                                    <tr key={grain.grain}>
                                        <td className="fw-semibold">
                                            {grain.grain}
                                        </td>
                                        <td className="app-table__numeric">
                                            {formatNumber(grain.rows)}
                                        </td>
                                        <td className="app-table__numeric">
                                            {formatNumber(grain.skus)}
                                        </td>
                                        <td className="app-text-muted small">
                                            {grain.firstDay === null
                                                ? 'Nothing synced'
                                                : `${grain.firstDay} – ${grain.lastDay}`}
                                        </td>
                                        <td className="app-table__numeric">
                                            {grain.daysBehind === null
                                                ? NO_VALUE
                                                : `${grain.daysBehind}d`}
                                        </td>
                                        <td className="app-table__numeric">
                                            {formatNumber(grain.unitsInWindow)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard
                    title="Sync stages"
                    subtitle="The most recent run of each stage against the BuyAbans back office."
                    flush
                >
                    {syncRuns.length > 0 ? (
                        <div className="app-table-wrap">
                            <table className="app-table">
                                <thead>
                                    <tr>
                                        <th>Stage</th>
                                        <th>Status</th>
                                        <th className="app-table__numeric">
                                            Fetched
                                        </th>
                                        <th className="app-table__numeric">
                                            Written
                                        </th>
                                        <th>Finished</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {syncRuns.map((run) => (
                                        <tr key={run.stage}>
                                            <td className="fw-semibold">
                                                {run.stage}
                                                {run.grain && (
                                                    <span className="app-text-muted small ms-2">
                                                        {run.grain}
                                                    </span>
                                                )}
                                            </td>
                                            <td>
                                                <StatusBadge
                                                    tone={statusTone(
                                                        run.status,
                                                    )}
                                                    label={run.status}
                                                />
                                            </td>
                                            <td className="app-table__numeric">
                                                {formatNumber(run.fetched)}
                                            </td>
                                            <td className="app-table__numeric">
                                                {formatNumber(run.written)}
                                            </td>
                                            <td className="app-text-muted small">
                                                {run.finishedAt ?? NO_VALUE}
                                                {run.stale && (
                                                    <StatusBadge
                                                        tone="warning"
                                                        label={`${run.hoursAgo}h ago`}
                                                        className="ms-2"
                                                    />
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-4">
                            <EmptyState
                                compact
                                title="No sync has run yet"
                                description="Run app:sync-buyabans to pull the catalogue and demand history."
                            />
                        </div>
                    )}
                </SectionCard>

                <div className="row g-3">
                    <div className="col-xl-6">
                        <SectionCard
                            title="Forecast pipeline"
                            subtitle="The last batch, and what is waiting on a decision."
                            className="h-100"
                        >
                            <dl className="row g-3 mb-0">
                                <div className="col-6">
                                    <dt className="app-subtitle">Latest run</dt>
                                    <dd className="fw-semibold mb-0">
                                        {pipeline.latestRun ? (
                                            <>
                                                #{pipeline.latestRun.id}{' '}
                                                <StatusBadge
                                                    tone={statusTone(
                                                        pipeline.latestRun
                                                            .status,
                                                    )}
                                                    label={
                                                        pipeline.latestRun
                                                            .status
                                                    }
                                                />
                                            </>
                                        ) : (
                                            NO_VALUE
                                        )}
                                    </dd>
                                </div>

                                <div className="col-6">
                                    <dt className="app-subtitle">
                                        Forecasts produced
                                    </dt>
                                    <dd className="fw-semibold mb-0">
                                        {pipeline.latestRun
                                            ? `${formatNumber(pipeline.latestRun.forecasts)} over ${pipeline.latestRun.horizonDays} days`
                                            : NO_VALUE}
                                    </dd>
                                </div>

                                <div className="col-6">
                                    <dt className="app-subtitle">
                                        Run duration
                                    </dt>
                                    <dd className="fw-semibold mb-0">
                                        {formatDuration(
                                            pipeline.latestRun
                                                ?.durationSeconds ?? null,
                                        )}
                                    </dd>
                                </div>

                                <div className="col-6">
                                    <dt className="app-subtitle">
                                        Awaiting a decision
                                    </dt>
                                    <dd className="fw-semibold mb-0">
                                        {formatNumber(
                                            pipeline.openRecommendations,
                                        )}
                                    </dd>
                                </div>

                                <div className="col-12">
                                    <dt className="app-subtitle">
                                        Forecasts scored for accuracy
                                    </dt>
                                    <dd className="fw-semibold mb-0">
                                        {formatNumber(pipeline.scoredForecasts)}
                                    </dd>
                                    {pipeline.scoredForecasts === 0 && (
                                        <p className="app-subtitle mb-0">
                                            None yet — a forecast can only be
                                            scored once its window has fully
                                            elapsed.
                                        </p>
                                    )}
                                </div>

                                {pipeline.latestRun?.error && (
                                    <div className="col-12">
                                        <dt className="app-subtitle">
                                            Last error
                                        </dt>
                                        <dd className="mb-0 app-text-muted small">
                                            {pipeline.latestRun.error}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </SectionCard>
                    </div>

                    <div className="col-xl-6">
                        <SectionCard
                            title="Queue"
                            subtitle="A batch that outgrows its worker timeout is killed without failing, so a job waiting far longer than a run takes is the symptom to watch."
                            className="h-100"
                        >
                            <dl className="row g-3 mb-0">
                                <div className="col-6">
                                    <dt className="app-subtitle">Driver</dt>
                                    <dd className="fw-semibold mb-0">
                                        {queue.driver}
                                    </dd>
                                </div>

                                <div className="col-6">
                                    <dt className="app-subtitle">Waiting</dt>
                                    <dd className="fw-semibold mb-0">
                                        {formatNumber(queue.pending)}
                                    </dd>
                                </div>

                                <div className="col-6">
                                    <dt className="app-subtitle">Failed</dt>
                                    <dd className="fw-semibold mb-0">
                                        {queue.failed > 0 ? (
                                            <StatusBadge
                                                tone="danger"
                                                label={formatNumber(
                                                    queue.failed,
                                                )}
                                            />
                                        ) : (
                                            '0'
                                        )}
                                    </dd>
                                </div>

                                <div className="col-6">
                                    <dt className="app-subtitle">
                                        Oldest waiting
                                    </dt>
                                    <dd className="fw-semibold mb-0">
                                        {queue.oldestPendingMinutes === null
                                            ? NO_VALUE
                                            : `${formatNumber(queue.oldestPendingMinutes)} min`}
                                    </dd>
                                </div>
                            </dl>
                        </SectionCard>
                    </div>
                </div>

                <div className="row g-3">
                    <div className="col-xl-5">
                        <SectionCard
                            title="Runtime"
                            subtitle="What is actually running, not what is pinned."
                            className="h-100"
                        >
                            <dl className="row g-3 mb-0">
                                {Object.entries(runtime).map(([key, value]) => (
                                    <div className="col-6" key={key}>
                                        <dt className="app-subtitle text-capitalize">
                                            {key
                                                .replace(/([A-Z])/g, ' $1')
                                                .toLowerCase()}
                                        </dt>
                                        <dd className="fw-semibold mb-0">
                                            {typeof value === 'boolean'
                                                ? value
                                                    ? 'on'
                                                    : 'off'
                                                : value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </SectionCard>
                    </div>

                    <div className="col-xl-7">
                        <SectionCard
                            title="Largest tables"
                            subtitle={
                                storage
                                    ? 'Row counts are the engine’s own estimates, so they drift from an exact count.'
                                    : 'This database driver cannot report table sizes.'
                            }
                            className="h-100"
                            flush
                        >
                            {storage ? (
                                <div className="app-table-wrap">
                                    <table className="app-table">
                                        <thead>
                                            <tr>
                                                <th>Table</th>
                                                <th className="app-table__numeric">
                                                    Rows (est.)
                                                </th>
                                                <th className="app-table__numeric">
                                                    Size
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {storage.tables.map((table) => (
                                                <tr key={table.name}>
                                                    <td className="fw-semibold">
                                                        {table.name}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {formatNumber(
                                                            table.rows,
                                                        )}
                                                    </td>
                                                    <td className="app-table__numeric">
                                                        {table.megabytes} MB
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="p-4">
                                    <EmptyState
                                        compact
                                        icon={HardDrive}
                                        title="Sizes unavailable"
                                        description="Table sizes come from information_schema, which only MySQL provides."
                                    />
                                </div>
                            )}
                        </SectionCard>
                    </div>
                </div>
            </div>
        </>
    );
}

SystemHealthIndex.layout = {
    breadcrumbs: [{ title: 'System health', href: index() }],
};
