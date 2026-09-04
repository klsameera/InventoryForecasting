import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/product-variant';
import type { Option, ProductVariant } from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    product_id?: string;
    status?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    variants: Paginated<ProductVariant>;
    filters: Filters;
    productOptions: Option[];
};

export default function ProductVariantIndex({
    variants,
    filters,
    productOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [productId, setProductId] = useState(filters.product_id ?? '');
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
                product_id: productId || undefined,
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

    const canReset = search !== '' || productId !== '' || status !== '';

    const columns: Column<ProductVariant>[] = [
        {
            key: 'name',
            header: 'Variant',
            sortable: true,
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.name}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product?.name ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'attribute_values',
            header: 'Attributes',
            cell: (row) =>
                row.attribute_values && row.attribute_values.length > 0
                    ? row.attribute_values
                          .map(
                              (value) =>
                                  `${value.attribute_name}: ${value.value}`,
                          )
                          .join(', ')
                    : '—',
        },
        {
            key: 'skus_count',
            header: 'SKUs',
            numeric: true,
            cell: (row) => row.skus_count ?? 0,
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
                eyebrow="Catalog"
                title="Variants"
                description="Attribute combinations that make up configurable products."
            />

            <DataTable
                columns={columns}
                rows={variants.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={variants}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No variants yet"
                emptyDescription="Variants arrive from the BuyAbans back office — run a sync to load them."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search variants…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setProductId('');
                            setStatus('');
                            reload({
                                search: undefined,
                                product_id: undefined,
                                status: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={productId}
                            onChange={(event) => {
                                setProductId(event.target.value);
                                reload({
                                    product_id: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by product"
                        >
                            <option value="">All products</option>
                            {productOptions.map((option) => (
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
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
