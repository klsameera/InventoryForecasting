import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/purchase-order';
import type { Option } from '@/types/catalog';
import type {
    PurchaseOrder,
    StatusOption,
    PurchaseOrderStatus as Status,
} from '@/types/sales-purchasing';
import type { Paginated, StatusTone, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    status?: string;
    supplier_id?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    purchaseOrders: Paginated<PurchaseOrder>;
    filters: Filters;
    supplierOptions: Option[];
    statuses: StatusOption<Status>[];
};

const STATUS_TONES: Record<Status, StatusTone> = {
    draft: 'secondary',
    approved: 'info',
    ordered: 'primary',
    partially_received: 'warning',
    received: 'success',
    cancelled: 'danger',
};

export default function PurchaseOrderIndex({
    purchaseOrders,
    filters,
    supplierOptions,
    statuses,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [supplierId, setSupplierId] = useState(filters.supplier_id ?? '');
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
                supplier_id: supplierId || undefined,
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

    const canReset = search !== '' || status !== '' || supplierId !== '';

    const columns: Column<PurchaseOrder>[] = [
        {
            key: 'po_number',
            header: 'PO number',
            sortable: true,
            cell: (row) => <span className="fw-semibold">{row.po_number}</span>,
        },
        {
            key: 'supplier',
            header: 'Supplier',
            cell: (row) => row.supplier?.name ?? '—',
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => row.warehouse?.name ?? '—',
        },
        {
            key: 'order_date',
            header: 'Order date',
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
                eyebrow="Purchasing"
                title="Purchase orders"
                description="Purchase order history. Read-only — purchasing happens in the BuyAbans back office."
            />

            <DataTable
                columns={columns}
                rows={purchaseOrders.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={purchaseOrders}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No purchase orders yet"
                emptyDescription="No purchase orders recorded."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by PO number…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setStatus('');
                            setSupplierId('');
                            reload({
                                search: undefined,
                                status: undefined,
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
