import { Link, router } from '@inertiajs/react';
import { Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { create, index, retry } from '@/routes/forecast-run';
import type { ForecastRun, ForecastRunStatus } from '@/types/forecasting';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = { status?: string };

type Props = {
    runs: Paginated<ForecastRun>;
    filters: Filters;
};

const STATUS_TONES: Record<ForecastRunStatus, StatusTone> = {
    queued: 'secondary',
    processing: 'info',
    completed: 'success',
    failed: 'danger',
};

export default function ForecastRunIndex({ runs, filters }: Props) {
    const [status, setStatus] = useState(filters.status ?? '');

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            { status: status || undefined, ...overrides },
            { preserveState: true, replace: true },
        );
    }

    const columns: Column<ForecastRun>[] = [
        {
            key: 'id',
            header: 'Run',
            cell: (row) => `#${row.id}`,
        },
        {
            key: 'horizon_days',
            header: 'Horizon',
            cell: (row) => `${row.horizon_days} days`,
        },
        {
            key: 'warehouse_ids',
            header: 'Warehouses',
            cell: (row) => row.warehouse_ids?.join(', ') ?? 'All',
        },
        {
            key: 'forecasts_count',
            header: 'Forecasts',
            numeric: true,
            cell: (row) => row.forecasts_count ?? 0,
        },
        {
            key: 'model_version',
            header: 'Model',
            cell: (row) => row.model_version ?? '—',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <StatusBadge
                    tone={STATUS_TONES[row.status]}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'created_at',
            header: 'Queued',
            cell: (row) => row.created_at ?? '—',
        },
        {
            key: 'error_message',
            header: 'Outcome',
            cell: (row) =>
                row.error_message ? (
                    <div className="d-flex flex-column align-items-start gap-2">
                        {/* A refused run produces no substitute figures, so the
                            only useful next step is to ask again once the cause
                            is fixed. */}
                        <span className="app-text-muted small">
                            {row.error_message}
                        </span>
                        <button
                            type="button"
                            className="btn btn-soft-warning btn-sm"
                            onClick={() =>
                                router.post(
                                    retry.url({ id: row.id }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RotateCcw aria-hidden="true" />
                            Try again
                        </button>
                    </div>
                ) : (
                    <span className="app-text-muted small">
                        {(row.forecasts_count ?? 0) > 0
                            ? 'All series forecast'
                            : '—'}
                    </span>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Forecasting"
                title="Forecast runs"
                description="Each run asks the ML service to predict demand for a set of warehouses over a horizon — currently a labeled statistical baseline, not a trained model. See ml-service/README.md."
                actions={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New forecast run
                    </Link>
                }
            />

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
                emptyTitle="No forecast runs yet"
                emptyDescription="Queue a run to generate demand predictions."
                emptyAction={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New forecast run
                    </Link>
                }
                toolbar={
                    <TableFilters
                        search=""
                        onSearchChange={() => {}}
                        canReset={status !== ''}
                        onReset={() => {
                            setStatus('');
                            reload({ status: undefined, page: 1 });
                        }}
                    >
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
                            <option value="">All statuses</option>
                            <option value="queued">Queued</option>
                            <option value="processing">Processing</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
