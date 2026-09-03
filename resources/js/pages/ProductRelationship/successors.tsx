import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { successors } from '@/routes/product-relationship';
import type { SuccessorCandidate } from '@/types/advanced-intelligence';
import type { Option } from '@/types/catalog';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    category_id?: string;
};

type Props = {
    rows: Paginated<SuccessorCandidate>;
    filters: Filters;
    categoryOptions: Option[];
};

export default function ProductRelationshipSuccessors({
    rows,
    filters,
    categoryOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            successors.url(),
            {
                search: debouncedSearch || undefined,
                category_id: categoryId || undefined,
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

    const columns: Column<SuccessorCandidate>[] = [
        {
            key: 'declining',
            header: 'Declining product',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.declining_sku}</p>
                    <p className="app-text-muted small mb-0">
                        {row.declining_product_name}
                    </p>
                    <StatusBadge
                        tone="warning"
                        label={row.declining_maturity_label}
                    />
                </div>
            ),
        },
        {
            key: 'successor',
            header: 'Possible successor',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.successor_sku}</p>
                    <p className="app-text-muted small mb-0">
                        {row.successor_product_name}
                    </p>
                    <StatusBadge
                        tone="info"
                        label={row.successor_maturity_label}
                    />
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Successor candidates"
                description="A declining or end-of-life product paired with a newer, same-brand product in the same category — a heuristic built from real forecast-maturity classifications, not an explicit 'replaces' link this catalog doesn't track."
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) =>
                    `${row.declining_sku_id}-${row.successor_sku_id}`
                }
                sort={null}
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No successor candidates found"
                emptyDescription="No declining or end-of-life product shares a category and brand with a newer product."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU or product…"
                        canReset={search !== '' || categoryId !== ''}
                        onReset={() => {
                            setSearch('');
                            setCategoryId('');
                            reload({
                                search: undefined,
                                category_id: undefined,
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
                    </TableFilters>
                }
            />
        </>
    );
}
