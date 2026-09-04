import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/product';
import type { Option, Product } from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    category_id?: string;
    brand_id?: string;
    product_type?: string;
    status?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    products: Paginated<Product>;
    filters: Filters;
    categoryOptions: Option[];
    brandOptions: Option[];
};

export default function ProductIndex({
    products,
    filters,
    categoryOptions,
    brandOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const [brandId, setBrandId] = useState(filters.brand_id ?? '');
    const [productType, setProductType] = useState(filters.product_type ?? '');
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
                category_id: categoryId || undefined,
                brand_id: brandId || undefined,
                product_type: productType || undefined,
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

    const canReset =
        search !== '' ||
        categoryId !== '' ||
        brandId !== '' ||
        productType !== '' ||
        status !== '';

    const columns: Column<Product>[] = [
        {
            key: 'name',
            header: 'Product',
            sortable: true,
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.name}</p>
                    <p className="app-text-muted small mb-0">
                        {row.model_number ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'category',
            header: 'Category',
            cell: (row) => row.category?.name ?? '—',
        },
        {
            key: 'brand',
            header: 'Brand',
            cell: (row) => row.brand?.name ?? '—',
        },
        {
            key: 'product_type',
            header: 'Type',
            cell: (row) => (
                <StatusBadge
                    tone={row.product_type === 'simple' ? 'info' : 'primary'}
                    dot={false}
                    label={
                        row.product_type === 'simple'
                            ? 'Simple'
                            : 'Configurable'
                    }
                />
            ),
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
                title="Products"
                description="Simple and configurable products in the catalog."
            />

            <DataTable
                columns={columns}
                rows={products.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={products}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No products yet"
                emptyDescription="Products arrive from the BuyAbans back office — run a sync to load them."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search products…"
                        canReset={canReset}
                        onReset={() => {
                            setSearch('');
                            setCategoryId('');
                            setBrandId('');
                            setProductType('');
                            setStatus('');
                            reload({
                                search: undefined,
                                category_id: undefined,
                                brand_id: undefined,
                                product_type: undefined,
                                status: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={categoryId}
                            onChange={(event) => {
                                setCategoryId(event.target.value);
                                reload({
                                    category_id:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by category"
                        >
                            <option value="">All categories</option>
                            {categoryOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={brandId}
                            onChange={(event) => {
                                setBrandId(event.target.value);
                                reload({
                                    brand_id: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by brand"
                        >
                            <option value="">All brands</option>
                            {brandOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={productType}
                            onChange={(event) => {
                                setProductType(event.target.value);
                                reload({
                                    product_type:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by product type"
                        >
                            <option value="">All types</option>
                            <option value="simple">Simple</option>
                            <option value="configurable">Configurable</option>
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
