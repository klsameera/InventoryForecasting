import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { create, edit, index } from '@/routes/sales-order';
import type {
    SalesOrder,
    SalesOrderStatus as Status,
    StatusOption,
} from '@/types/sales-purchasing';
import type { Paginated, StatusTone, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    status?: string;
    warehouse_id?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    salesOrders: Paginated<SalesOrder>;
    filters: Filters;
    statuses: StatusOption<Status>[];
};

const STATUS_TONES: Record<Status, StatusTone> = {
    draft: 'secondary',
    confirmed: 'success',
    cancelled: 'danger',
};

export default function SalesOrderIndex({
    salesOrders,
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

    const columns: Column<SalesOrder>[] = [
        {
            key: 'order_number',
            header: 'Order number',
            sortable: true,
            cell: (row) => (
                <Link href={edit(row.id)} className="fw-semibold">
                    {row.order_number}
                </Link>
            ),
        },
        {
            key: 'customer_name',
            header: 'Customer',
            cell: (row) => row.customer_name ?? '—',
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => row.warehouse?.name ?? '—',
        },
        {
            key: 'order_date',
            header: 'Date',
            sortable: true,
            cell: (row) => row.order_date,
        },
        {
            key: 'total',
            header: 'Total',
            numeric: true,
            sortable: true,
            cell: (row) => row.total.toFixed(2),
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
                eyebrow="Sales"
                title="Sales orders"
                description="Orders fulfilled from a warehouse, from draft through confirmation."
                actions={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New sales order
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={salesOrders.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={salesOrders}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No sales orders yet"
                emptyDescription="Create a sales order to record a sale and deduct stock."
                emptyAction={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New sales order
                    </Link>
                }
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by order number or customer…"
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
