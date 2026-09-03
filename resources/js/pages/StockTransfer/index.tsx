import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { create, edit, index } from '@/routes/stock-transfer';
import type {
    StatusOption,
    StockTransfer,
    StockTransferStatus as Status,
} from '@/types/sales-purchasing';
import type { Paginated, StatusTone, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    status?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    stockTransfers: Paginated<StockTransfer>;
    filters: Filters;
    statuses: StatusOption<Status>[];
};

const STATUS_TONES: Record<Status, StatusTone> = {
    draft: 'secondary',
    approved: 'info',
    dispatched: 'warning',
    received: 'success',
    cancelled: 'danger',
};

export default function StockTransferIndex({
    stockTransfers,
    filters,
    statuses,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const debouncedSearch = useDebouncedValue(search);

    const sort: TableSort | null = filters.sort
        ? {
              column: filters.sort,
              direction: filters.direction === 'desc' ? 'desc' : 'asc',
          }
        : null;

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                status: status || undefined,
                sort: filters.sort,
                direction: filters.direction,
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

    const canReset = search !== '' || status !== '';

    const columns: Column<StockTransfer>[] = [
        {
            key: 'transfer_number',
            header: 'Transfer number',
            sortable: true,
            cell: (row) => (
                <Link href={edit(row.id)} className="fw-semibold">
                    {row.transfer_number}
                </Link>
            ),
        },
        {
            key: 'source',
            header: 'From',
            cell: (row) => row.source_warehouse?.name ?? '—',
        },
        {
            key: 'destination',
            header: 'To',
            cell: (row) => row.destination_warehouse?.name ?? '—',
        },
        {
            key: 'transfer_date',
            header: 'Date',
            sortable: true,
            cell: (row) => row.transfer_date,
        },
        {
            key: 'items_count',
            header: 'Lines',
            numeric: true,
            cell: (row) => row.items_count ?? 0,
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (row) => (
                <StatusBadge
                    tone={STATUS_TONES[row.status]}
                    label={row.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Inventory"
                title="Stock transfers"
                description="Move stock between warehouses, from draft through receiving."
                actions={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New transfer
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={stockTransfers.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={stockTransfers}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No stock transfers yet"
                emptyDescription="Move stock between warehouses to balance availability."
                emptyAction={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New transfer
                    </Link>
                }
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by transfer number…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setStatus('');
                            reload({
                                search: undefined,
                                status: undefined,
                                page: 1,
                            });
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
                            {statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
