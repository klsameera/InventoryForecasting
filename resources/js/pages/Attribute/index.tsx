import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/attribute';
import type { Attribute } from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    attributes: Paginated<Attribute>;
    filters: Filters;
};

export default function AttributeIndex({ attributes, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
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

    const columns: Column<Attribute>[] = [
        {
            key: 'name',
            header: 'Name',
            sortable: true,
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.name}</p>
                    <p className="app-text-muted small mb-0">{row.code}</p>
                </div>
            ),
        },
        {
            key: 'data_type',
            header: 'Type',
            cell: (row) => (
                <StatusBadge tone="info" dot={false} label={row.data_type} />
            ),
        },
        {
            key: 'values_count',
            header: 'Values',
            numeric: true,
            cell: (row) => row.values_count ?? 0,
        },
        {
            key: 'forecast_relevant',
            header: 'Forecast relevant',
            cell: (row) =>
                row.forecast_relevant ? (
                    <StatusBadge tone="success" label="Yes" />
                ) : (
                    <StatusBadge tone="secondary" label="No" />
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Catalog"
                title="Attributes"
                description="Size, color and other properties variants are built from."
            />

            <DataTable
                columns={columns}
                rows={attributes.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={attributes}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No attributes yet"
                emptyDescription="Attributes arrive from the BuyAbans back office — run a sync to load them."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search attributes…"
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
