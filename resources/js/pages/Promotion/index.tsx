import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { create, edit, impact, index } from '@/routes/promotion';
import type { Promotion } from '@/types/advanced-intelligence';
import type { SkuOption } from '@/types/catalog';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = {
    search?: string;
    sku_id?: string;
};

type Props = {
    promotions: Paginated<Promotion>;
    filters: Filters;
    skuOptions: SkuOption[];
};

const STATE_TONES: Record<Promotion['state'], StatusTone> = {
    upcoming: 'info',
    active: 'success',
    ended: 'secondary',
};

export default function PromotionIndex({
    promotions,
    filters,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                sku_id: skuId || undefined,
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

    const columns: Column<Promotion>[] = [
        {
            key: 'name',
            header: 'Name',
            cell: (row) => (
                <Link
                    href={edit.url(row.id)}
                    className="fw-semibold text-decoration-none"
                >
                    {row.name}
                </Link>
            ),
        },
        {
            key: 'discount',
            header: 'Discount',
            cell: (row) =>
                row.discount_type === 'PERCENTAGE'
                    ? `${row.discount_value}%`
                    : row.discount_value.toFixed(2),
        },
        {
            key: 'dates',
            header: 'Dates',
            cell: (row) => `${row.start_date} – ${row.end_date}`,
        },
        {
            key: 'skus_count',
            header: 'SKUs',
            numeric: true,
            cell: (row) => row.skus_count ?? 0,
        },
        {
            key: 'state',
            header: 'Status',
            cell: (row) => (
                <StatusBadge
                    tone={STATE_TONES[row.state]}
                    label={
                        row.state.charAt(0).toUpperCase() + row.state.slice(1)
                    }
                />
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            hideLabelOnMobile: true,
            cell: (row) =>
                row.state === 'ended' ? (
                    <Link
                        href={impact.url(row.id)}
                        className="btn btn-sm btn-surface"
                    >
                        View impact
                    </Link>
                ) : (
                    '—'
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Promotions"
                description="Discounts recorded against a set of SKUs — once a promotion has ended, its real effect on demand can be measured against a matching pre-promotion baseline."
                actions={
                    <Link href={create.url()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New promotion
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={promotions.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={promotions}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No promotions yet"
                emptyDescription="Record a promotion to start measuring its impact."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by name…"
                        canReset={search !== '' || skuId !== ''}
                        onReset={() => {
                            setSearch('');
                            setSkuId('');
                            reload({
                                search: undefined,
                                sku_id: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={skuId}
                            onChange={(event) => {
                                setSkuId(event.target.value);
                                reload({
                                    sku_id: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by SKU"
                        >
                            <option value="">All SKUs</option>
                            {skuOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.sku}
                                </option>
                            ))}
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
