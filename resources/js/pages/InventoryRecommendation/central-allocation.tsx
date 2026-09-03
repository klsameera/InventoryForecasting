import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import {
    centralAllocation,
    index as recommendationsIndex,
} from '@/routes/inventory-recommendation';
import type { CentralAllocationRow } from '@/types/inventory-optimization';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = {
    search?: string;
};

type Props = {
    rows: Paginated<CentralAllocationRow>;
    filters: Filters;
};

const RISK_TONES: Record<string, StatusTone> = {
    NONE: 'success',
    MODERATE: 'warning',
    CRITICAL: 'danger',
};

export default function InventoryRecommendationCentralAllocation({
    rows,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            centralAllocation.url(),
            { search: debouncedSearch || undefined, ...overrides },
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

    const columns: Column<CentralAllocationRow>[] = [
        {
            key: 'sku',
            header: 'SKU',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku ?? '—'}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product_name ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'warehouse_count',
            header: 'Warehouses needing',
            numeric: true,
            cell: (row) => row.warehouse_count,
        },
        {
            key: 'total_recommended_qty',
            header: 'Combined need',
            numeric: true,
            cell: (row) => (
                <span className="fw-semibold">{row.total_recommended_qty}</span>
            ),
        },
        {
            key: 'breakdown',
            header: 'Per-warehouse breakdown',
            cell: (row) => (
                <div className="d-flex flex-wrap gap-1">
                    {row.breakdown.map((entry) => (
                        <StatusBadge
                            key={entry.warehouse_id}
                            tone={
                                entry.stockout_risk
                                    ? (RISK_TONES[entry.stockout_risk] ??
                                      'secondary')
                                    : 'secondary'
                            }
                            label={`${entry.warehouse_name ?? '—'}: ${entry.recommended_qty}`}
                        />
                    ))}
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Forecasting"
                title="Central allocation"
                description="SKUs with an open purchase recommendation in two or more warehouses — combined need for a single consolidated supplier order, broken down by location. A straight sum of each warehouse's own recommended quantity, not a new formula."
                actions={
                    <Link
                        href={recommendationsIndex.url()}
                        className="btn btn-surface"
                    >
                        <ArrowLeft aria-hidden="true" />
                        Back to recommendations
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => row.sku_id}
                sort={null}
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="Nothing to allocate centrally"
                emptyDescription="No SKU currently has an open purchase recommendation spanning two or more warehouses."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU or product…"
                        canReset={search !== ''}
                        onReset={() => {
                            setSearch('');
                            reload({ search: undefined, page: 1 });
                        }}
                    />
                }
            />
        </>
    );
}
