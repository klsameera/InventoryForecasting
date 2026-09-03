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
import { capture, index } from '@/routes/supplier-performance';
import type { SupplierPerformanceMetric } from '@/types/advanced-intelligence';
import type { Option } from '@/types/catalog';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = {
    search?: string;
    supplier_id?: string;
};

type Props = {
    metrics: Paginated<SupplierPerformanceMetric>;
    filters: Filters;
    supplierOptions: Option[];
};

function leadTimeTone(observed: number, configured: number): StatusTone {
    if (observed <= configured) {
        return 'success';
    }

    return observed <= configured * 1.25 ? 'warning' : 'danger';
}

export default function SupplierPerformanceIndex({
    metrics,
    filters,
    supplierOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [supplierId, setSupplierId] = useState(filters.supplier_id ?? '');
    const [month, setMonth] = useState('');
    const [capturing, setCapturing] = useState(false);
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                supplier_id: supplierId || undefined,
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

    function runCapture() {
        setCapturing(true);
        router.post(capture.url(), month ? { month } : {}, {
            preserveScroll: true,
            onFinish: () => setCapturing(false),
        });
    }

    const columns: Column<SupplierPerformanceMetric>[] = [
        {
            key: 'supplier',
            header: 'Supplier',
            cell: (row) => row.supplier?.name ?? '—',
        },
        {
            key: 'period',
            header: 'Period',
            cell: (row) => `${row.period_start} – ${row.period_end}`,
        },
        {
            key: 'lead_time',
            header: 'Actual vs. configured lead time',
            cell: (row) => {
                if (row.average_lead_time_days === null || !row.supplier) {
                    return '—';
                }

                return (
                    <div>
                        <StatusBadge
                            tone={leadTimeTone(
                                row.average_lead_time_days,
                                row.supplier.default_lead_time_days,
                            )}
                            label={`${row.average_lead_time_days.toFixed(1)}d actual`}
                        />
                        <p className="app-text-muted small mb-0 mt-1">
                            Configured: {row.supplier.default_lead_time_days}d
                            {row.lead_time_std_dev !== null &&
                                ` · ±${row.lead_time_std_dev.toFixed(1)}d`}
                        </p>
                    </div>
                );
            },
        },
        {
            key: 'on_time_percentage',
            header: 'On-time',
            numeric: true,
            cell: (row) =>
                row.on_time_percentage !== null
                    ? `${row.on_time_percentage.toFixed(0)}%`
                    : '—',
        },
        {
            key: 'fill_rate',
            header: 'Fill rate',
            numeric: true,
            cell: (row) =>
                row.fill_rate !== null ? `${row.fill_rate.toFixed(0)}%` : '—',
        },
        {
            key: 'ordered_received',
            header: 'Ordered / Received',
            numeric: true,
            cell: (row) => `${row.ordered_qty} / ${row.received_qty}`,
        },
        {
            key: 'quality_issue_rate',
            header: 'Quality issues',
            numeric: true,
            cell: () => (
                <span
                    className="app-text-muted"
                    title="Not tracked in this application — no goods receipt records a quality/defect signal"
                >
                    Not tracked
                </span>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Supplier performance"
                description="Real observed lead time, on-time delivery and fill rate per supplier per month — computed from actual purchase order and goods receipt dates, compared against each supplier's configured lead time."
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <input
                            type="month"
                            className="form-control"
                            value={month}
                            onChange={(event) => setMonth(event.target.value)}
                            max={new Date().toISOString().slice(0, 7)}
                            aria-label="Month to capture"
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
                            Capture month
                        </button>
                    </div>
                }
            />

            <DataTable
                columns={columns}
                rows={metrics.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={metrics}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No supplier performance captured yet"
                emptyDescription="Capture a month above, or wait for the monthly schedule to run."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by supplier…"
                        canReset={search !== '' || supplierId !== ''}
                        onReset={() => {
                            setSearch('');
                            setSupplierId('');
                            reload({
                                search: undefined,
                                supplier_id: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={supplierId}
                            onChange={(event) => {
                                setSupplierId(event.target.value);
                                reload({
                                    supplier_id:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by supplier"
                        >
                            <option value="">All suppliers</option>
                            {supplierOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
