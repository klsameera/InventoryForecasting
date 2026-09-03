import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { create, index } from '@/routes/sales-return';
import type { SalesReturn } from '@/types/sales-purchasing';
import type { Paginated } from '@/types/ui';

type Filters = { search?: string };

type Props = {
    salesReturns: Paginated<SalesReturn>;
    filters: Filters;
};

export default function SalesReturnIndex({ salesReturns, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
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

    const columns: Column<SalesReturn>[] = [
        {
            key: 'return_number',
            header: 'Return number',
            cell: (row) => (
                <p className="fw-semibold mb-0">{row.return_number}</p>
            ),
        },
        {
            key: 'sales_order',
            header: 'Sales order',
            cell: (row) => row.sales_order?.order_number ?? '—',
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => row.warehouse?.name ?? '—',
        },
        {
            key: 'return_date',
            header: 'Date',
            cell: (row) => row.return_date,
        },
        {
            key: 'reason',
            header: 'Reason',
            cell: (row) => row.reason ?? '—',
        },
        {
            key: 'items_count',
            header: 'Lines',
            numeric: true,
            cell: (row) => row.items_count ?? 0,
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Sales"
                title="Sales returns"
                description="Every return recorded against a confirmed sales order. Immutable once posted."
                actions={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        Record return
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={salesReturns.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={salesReturns}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No sales returns yet"
                emptyDescription="Record a return against a confirmed sales order."
                emptyAction={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        Record return
                    </Link>
                }
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by return number…"
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
