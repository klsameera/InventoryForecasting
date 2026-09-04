import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { index } from '@/routes/goods-receipt';
import type { GoodsReceipt } from '@/types/sales-purchasing';
import type { Paginated } from '@/types/ui';

type Filters = { search?: string };

type Props = {
    goodsReceipts: Paginated<GoodsReceipt>;
    filters: Filters;
};

export default function GoodsReceiptIndex({ goodsReceipts, filters }: Props) {
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

    const columns: Column<GoodsReceipt>[] = [
        {
            key: 'receipt_number',
            header: 'Receipt number',
            cell: (row) => (
                <p className="fw-semibold mb-0">{row.receipt_number}</p>
            ),
        },
        {
            key: 'purchase_order',
            header: 'Purchase order',
            cell: (row) => row.purchase_order?.po_number ?? '—',
        },
        {
            key: 'supplier',
            header: 'Supplier',
            cell: (row) => row.purchase_order?.supplier_name ?? '—',
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => row.warehouse?.name ?? '—',
        },
        {
            key: 'received_date',
            header: 'Received',
            cell: (row) => row.received_date,
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
                eyebrow="Purchasing"
                title="Goods receipts"
                description="Goods receipt history. Read-only — receiving happens in the BuyAbans back office."
            />

            <DataTable
                columns={columns}
                rows={goodsReceipts.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={goodsReceipts}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No goods receipts yet"
                emptyDescription="No goods receipts recorded."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by receipt number…"
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
