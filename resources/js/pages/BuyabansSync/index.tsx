import { router } from '@inertiajs/react';
import {
    Boxes,
    CloudDownload,
    Layers,
    PlugZap,
    Tags,
    Warehouse,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import Alert from '@/components/alert';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatCard from '@/components/stat-card';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index, probe, run } from '@/routes/buyabans-sync';
import type {
    BuyabansSyncRun,
    BuyabansSyncSummary,
    DemandGrain,
    SyncStage,
} from '@/types/integration';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    stage?: string;
    status?: string;
};

type Props = {
    runs: Paginated<BuyabansSyncRun>;
    filters: Filters;
    summary: BuyabansSyncSummary;
};

const STAGES: { value: SyncStage; label: string }[] = [
    { value: 'all', label: 'Everything' },
    { value: 'locations', label: 'Locations' },
    { value: 'categories', label: 'Categories' },
    { value: 'brands', label: 'Brands' },
    { value: 'attributes', label: 'Attributes' },
    { value: 'products', label: 'Products, variants & SKUs' },
    { value: 'stock', label: 'Stock levels' },
    { value: 'demand', label: 'Demand history' },
];

const GRAINS: { value: DemandGrain; label: string }[] = [
    { value: 'warehouse', label: 'Per warehouse' },
    { value: 'channel', label: 'Per channel' },
    { value: 'national', label: 'National' },
];

function statusTone(status: string) {
    if (status === 'success') {
        return 'success' as const;
    }

    return status === 'failed' ? ('danger' as const) : ('info' as const);
}

function formatNumber(value: number) {
    return new Intl.NumberFormat().format(value);
}

function formatDuration(seconds: number | null) {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    return `${minutes}m ${seconds % 60}s`;
}

export default function BuyabansSyncIndex({ runs, filters, summary }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [stageFilter, setStageFilter] = useState(filters.stage ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [stage, setStage] = useState<SyncStage>('all');
    const [grain, setGrain] = useState<DemandGrain>('warehouse');
    const [days, setDays] = useState('30');
    const [busy, setBusy] = useState(false);
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                stage: stageFilter || undefined,
                status: status || undefined,
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

    function runProbe() {
        setBusy(true);
        router.post(
            probe.url(),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    function runSync() {
        setBusy(true);
        router.post(
            run.url(),
            { stage, grain, days: Number(days) || undefined },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    const columns: Column<BuyabansSyncRun>[] = [
        {
            key: 'stage',
            header: 'Stage',
            cell: (row) => <span className="fw-semibold">{row.stage}</span>,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <StatusBadge tone={statusTone(row.status)} label={row.status} />
            ),
        },
        {
            key: 'records_fetched',
            header: 'Fetched',
            numeric: true,
            cell: (row) => formatNumber(row.records_fetched),
        },
        {
            key: 'records_written',
            header: 'Written',
            numeric: true,
            cell: (row) => formatNumber(row.records_written),
        },
        {
            key: 'pages',
            header: 'Pages',
            numeric: true,
            cell: (row) => formatNumber(row.pages),
        },
        {
            key: 'window',
            header: 'Window',
            cell: (row) =>
                row.from_date
                    ? `${row.from_date} → ${row.to_date ?? '—'}${row.grain ? ` (${row.grain})` : ''}`
                    : '—',
        },
        {
            key: 'started_at',
            header: 'Started',
            cell: (row) => row.started_at ?? '—',
        },
        {
            key: 'duration_seconds',
            header: 'Took',
            numeric: true,
            cell: (row) => formatDuration(row.duration_seconds),
        },
        {
            key: 'message',
            header: 'Detail',
            cell: (row) =>
                row.message ? (
                    <span className="app-text-muted small">{row.message}</span>
                ) : (
                    <span className="app-text-muted small">
                        {row.summary ? JSON.stringify(row.summary) : '—'}
                    </span>
                ),
        },
    ];

    const canReset = search !== '' || stageFilter !== '' || status !== '';

    return (
        <>
            <PageHeader
                eyebrow="Integration"
                title="BuyAbans data sync"
                description="This application forecasts demand — it does not operate stock. Catalog, locations, stock levels and sales history are pulled read-only from the BuyAbans back office."
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <button
                            type="button"
                            className="btn btn-outline-secondary"
                            disabled={busy}
                            onClick={runProbe}
                        >
                            <PlugZap aria-hidden="true" />
                            Test connection
                        </button>
                        <button
                            type="button"
                            className="btn btn-gradient"
                            disabled={busy}
                            onClick={runSync}
                        >
                            {busy ? (
                                <Spinner size="sm" />
                            ) : (
                                <CloudDownload aria-hidden="true" />
                            )}
                            Sync now
                        </button>
                    </div>
                }
            />

            {!summary.configured && (
                <Alert tone="warning" className="mb-4">
                    No API credentials are configured for{' '}
                    <strong>{summary.endpoint}</strong>. Set{' '}
                    <code>BUYABANS_CLIENT_ID</code> and{' '}
                    <code>BUYABANS_CLIENT_SECRET</code>, then test the
                    connection. Until then nothing can be synced.
                </Alert>
            )}

            <div className="row g-3 mb-4">
                <div className="col-12 col-sm-6 col-xl-3">
                    <StatCard
                        label="Categories synced"
                        value={formatNumber(summary.catalog.categories)}
                        icon={Layers}
                        tone="brand"
                    />
                </div>
                <div className="col-12 col-sm-6 col-xl-3">
                    <StatCard
                        label="Brands synced"
                        value={formatNumber(summary.catalog.brands)}
                        icon={Tags}
                        tone="info"
                    />
                </div>
                <div className="col-12 col-sm-6 col-xl-3">
                    <StatCard
                        label="SKUs"
                        value={formatNumber(summary.catalog.skus)}
                        icon={Boxes}
                        tone="success"
                    />
                </div>
                <div className="col-12 col-sm-6 col-xl-3">
                    <StatCard
                        label="Warehouses"
                        value={formatNumber(summary.catalog.warehouses)}
                        icon={Warehouse}
                        tone="warning"
                    />
                </div>
            </div>

            <SectionCard
                title="Demand history held"
                subtitle="Rows of different grains describe the same sales from different angles — they are never added together."
                className="mb-4"
            >
                {summary.by_grain.length === 0 ? (
                    <p className="app-text-muted mb-0">
                        No demand history synced yet. Run a sync to pull it from
                        the back office.
                    </p>
                ) : (
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Grain</th>
                                    <th scope="col" className="text-end">
                                        Rows
                                    </th>
                                    <th scope="col" className="text-end">
                                        SKUs
                                    </th>
                                    <th scope="col" className="text-end">
                                        Locations
                                    </th>
                                    <th scope="col" className="text-end">
                                        Units sold
                                    </th>
                                    <th scope="col">Covers</th>
                                    <th scope="col" className="text-end">
                                        Unmatched SKUs
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {summary.by_grain.map((row) => (
                                    <tr key={row.grain}>
                                        <td className="fw-semibold">
                                            {row.grain}
                                        </td>
                                        <td className="text-end">
                                            {formatNumber(row.rows)}
                                        </td>
                                        <td className="text-end">
                                            {formatNumber(row.skus)}
                                        </td>
                                        <td className="text-end">
                                            {formatNumber(row.locations)}
                                        </td>
                                        <td className="text-end">
                                            {formatNumber(
                                                Math.round(row.units),
                                            )}
                                        </td>
                                        <td>
                                            {row.first_date ?? '—'} →{' '}
                                            {row.last_date ?? '—'}
                                        </td>
                                        <td className="text-end">
                                            {row.unmatched_skus > 0 ? (
                                                <StatusBadge
                                                    tone="warning"
                                                    label={formatNumber(
                                                        row.unmatched_skus,
                                                    )}
                                                />
                                            ) : (
                                                '0'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </SectionCard>

            <SectionCard
                title="Run a sync"
                subtitle="A full history pull belongs on the app:sync-buyabans console command — this page is capped at 400 days so it cannot time out mid-request."
                className="mb-4"
            >
                <div className="row g-3 align-items-end">
                    <div className="col-12 col-md-4">
                        <label className="form-label" htmlFor="sync_stage">
                            Stage
                        </label>
                        <select
                            id="sync_stage"
                            className="form-select"
                            value={stage}
                            onChange={(event) =>
                                setStage(event.target.value as SyncStage)
                            }
                        >
                            {STAGES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="col-12 col-md-4">
                        <label className="form-label" htmlFor="sync_grain">
                            Demand grain
                        </label>
                        <select
                            id="sync_grain"
                            className="form-select"
                            value={grain}
                            onChange={(event) =>
                                setGrain(event.target.value as DemandGrain)
                            }
                        >
                            {GRAINS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="col-12 col-md-4">
                        <label className="form-label" htmlFor="sync_days">
                            Days of history
                        </label>
                        <input
                            id="sync_days"
                            type="number"
                            className="form-control"
                            min={1}
                            max={400}
                            value={days}
                            onChange={(event) => setDays(event.target.value)}
                        />
                    </div>
                </div>
            </SectionCard>

            <DataTable
                columns={columns}
                rows={runs.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={runs}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="Nothing synced yet"
                emptyDescription="Run a sync above, or wait for the nightly schedule."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search stage or error…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setStageFilter('');
                            setStatus('');
                            reload({
                                search: undefined,
                                stage: undefined,
                                status: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={stageFilter}
                            onChange={(event) => {
                                setStageFilter(event.target.value);
                                reload({
                                    stage: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by stage"
                        >
                            <option value="">All stages</option>
                            {STAGES.filter(
                                (option) => option.value !== 'all',
                            ).map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={status}
                            onChange={(event) => {
                                setStatus(event.target.value);
                                reload({
                                    status: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by status"
                        >
                            <option value="">Any status</option>
                            <option value="success">Success</option>
                            <option value="failed">Failed</option>
                            <option value="running">Running</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
