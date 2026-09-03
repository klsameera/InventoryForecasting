import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/price-elasticity';
import type { PriceElasticityRow } from '@/types/advanced-intelligence';
import type { SkuOption } from '@/types/catalog';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = {
    search?: string;
    sku_id?: string;
    lookback_days?: string;
};

type Props = {
    rows: Paginated<PriceElasticityRow>;
    filters: Filters;
    skuOptions: SkuOption[];
};

function elasticityTone(elasticity: number): StatusTone {
    if (elasticity > 0) {
        return 'warning';
    }

    return elasticity <= -1 ? 'danger' : 'success';
}

export default function PriceElasticityIndex({
    rows,
    filters,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const [lookbackDays, setLookbackDays] = useState(
        filters.lookback_days ?? '365',
    );
    const debouncedSearch = useDebouncedValue(search);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: debouncedSearch || undefined,
                sku_id: skuId || undefined,
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

    const columns: Column<PriceElasticityRow>[] = [
        {
            key: 'sku',
            header: 'SKU',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku}</p>
                    <p className="app-text-muted small mb-0">
                        {row.product_name}
                    </p>
                </div>
            ),
        },
        {
            key: 'distinct_price_points',
            header: 'Price points',
            numeric: true,
            cell: (row) => row.distinct_price_points,
        },
        {
            key: 'elasticity',
            header: 'Elasticity',
            numeric: true,
            cell: (row) => (
                <StatusBadge
                    tone={elasticityTone(row.elasticity)}
                    label={row.elasticity.toFixed(2)}
                />
            ),
        },
        {
            key: 'interpretation',
            header: 'Reading',
            cell: (row) => row.interpretation,
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Price elasticity"
                description="How much a SKU's demand moved with its actual selling price — the slope of ln(quantity) against ln(price) across its own real confirmed sales orders. A SKU that has only ever sold at one price has no variation to estimate this from and is excluded."
            />

            <DataTable
                columns={columns}
                rows={rows.data}
                rowKey={(row) => row.sku_id}
                sort={null}
                pagination={rows}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="Not enough price history yet"
                emptyDescription="No SKU in this window has sold at two or more distinct prices."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU or product…"
                        canReset={
                            search !== '' ||
                            skuId !== '' ||
                            lookbackDays !== '365'
                        }
                        onReset={() => {
                            setSearch('');
                            setSkuId('');
                            setLookbackDays('365');
                            reload({
                                search: undefined,
                                sku_id: undefined,
                                lookback_days: '365',
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
                            <option value="90">Last 90 days</option>
                            <option value="365">Last year</option>
                            <option value="730">Last 2 years</option>
                        </select>
                    </TableFilters>
                }
            />
        </>
    );
}
