import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { cannibalization } from '@/routes/product-relationship';
import type { CannibalizationCandidate } from '@/types/advanced-intelligence';
import type { Option } from '@/types/catalog';
import type { Paginated } from '@/types/ui';

type Filters = {
    search?: string;
    category_id?: string;
    lookback_days?: string;
};

type Props = {
    rows: Paginated<CannibalizationCandidate>;
    filters: Filters;
    categoryOptions: Option[];
};

export default function ProductRelationshipCannibalization({
    rows,
    filters,
    categoryOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const [lookbackDays, setLookbackDays] = useState(
        filters.lookback_days ?? '90',
    );
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            cannibalization.url(),
            {
                search: debouncedSearch || undefined,
                category_id: categoryId || undefined,
                lookback_days: lookbackDays,
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

    const columns: Column<CannibalizationCandidate>[] = [
        {
            key: 'sku_a',
            header: 'SKU A',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku_a}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product_a_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'sku_b',
            header: 'SKU B',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku_b}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product_b_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'correlation',
            header: 'Correlation',
            numeric: true,
            cell: (row) => (
                <StatusBadge
                    tone={row.correlation <= -0.7 ? 'danger' : 'warning'}
                    label={row.correlation.toFixed(2)}
                />
            ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Cannibalization candidates"
                description="Pairs of SKUs in the same category whose daily demand correlates negatively over the window — one selling more tends to coincide with the other selling less. A statistical candidate worth a look, not proof of cause and effect."
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => `${row.sku_a_id}-${row.sku_b_id}`}
                sort={null}
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No cannibalization candidates found"
                emptyDescription="No same-category pair had a strong enough negative correlation with enough overlapping history in this window."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU or product…"
                        canReset={
                            search !== '' ||
                            categoryId !== '' ||
                            lookbackDays !== '90'
                        }
                        onReset={() => {
                            setSearch('');
                            setCategoryId('');
                            setLookbackDays('90');
                            reload({
                                search: undefined,
                                category_id: undefined,
                                lookback_days: '90',
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
                            value={lookbackDays}
                            onChange={(event) => {
                                setLookbackDays(event.target.value);
                                reload({
                                    lookback_days: event.target.value,
                                    page: 1,
                                });
                            }}
                            aria-label="Lookback window"
                        >
                            <option value="30">Last 30 days</option>
                            <option value="90">Last 90 days</option>
                            <option value="180">Last 180 days</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
