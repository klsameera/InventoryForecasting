import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/supplier';
import type { Supplier } from '@/types/sales-purchasing';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    status?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    suppliers: Paginated<Supplier>;
    filters: Filters;
};

export default function SupplierIndex({ suppliers, filters }: Props) {
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

    const columns: Column<Supplier>[] = [
        {
            key: 'name',
            header: 'Name',
            sortable: true,
            cell: (row) => <p className="fw-semibold mb-0">{row.name}</p>,
        },
        {
            key: 'default_lead_time_days',
            header: 'Lead time',
            sortable: true,
            cell: (row) => `${row.default_lead_time_days} days`,
        },
        {
            key: 'supplier_skus_count',
            header: 'SKUs supplied',
            cell: (row) => row.supplier_skus_count ?? 0,
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (row) => (
                <StatusBadge
                    tone={row.status ? 'success' : 'secondary'}
                    label={row.status ? 'Active' : 'Inactive'}
                />
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Purchasing"
                title="Suppliers"
                description="Vendors and the SKUs they supply, with the lead times the reorder engine plans against. Read-only."
            />

            <DataTable
                columns={columns}
                rows={suppliers.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={suppliers}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No suppliers yet"
                emptyDescription="No suppliers recorded."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search suppliers…"
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
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
