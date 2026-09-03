import { Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import BrandController from '@/actions/App/Http/Controllers/BrandController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { create, edit, index } from '@/routes/brand';
import type { Brand } from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    status?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    brands: Paginated<Brand>;
    filters: Filters;
};

export default function BrandIndex({ brands, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);
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

    const columns: Column<Brand>[] = [
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
        {
            key: 'actions',
            header: 'Actions',
            hideLabelOnMobile: true,
            cell: (row) => (
                <div className="d-flex justify-content-end gap-2">
                    <Link
                        href={edit(row.id)}
                        className="btn btn-quiet btn-sm btn-icon"
                        aria-label={`Edit ${row.name}`}
                    >
                        <Pencil aria-hidden="true" />
                    </Link>
                    <button
                        type="button"
                        className="btn btn-quiet-danger btn-sm btn-icon"
                        aria-label={`Delete ${row.name}`}
                        onClick={() => setDeletingId(row.id)}
                    >
                        <Trash2 aria-hidden="true" />
                    </button>
                </div>
            ),
        },
    ];

    const deletingBrand = brands.data.find((row) => row.id === deletingId);

    function confirmDelete() {
        if (deletingId === null) {
            return;
        }

        setProcessing(true);

        router.delete(BrandController.delete.url(deletingId), {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setDeletingId(null);
            },
        });
    }

    return (
        <>
            <PageHeader
                eyebrow="Catalog"
                title="Brands"
                description="Product brands used across the catalog."
                actions={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New brand
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={brands.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={brands}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No brands yet"
                emptyDescription="Add your first brand to start tagging products."
                emptyAction={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New brand
                    </Link>
                }
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search brands…"
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

            <ConfirmDialog
                open={deletingId !== null}
                onCancel={() => setDeletingId(null)}
                onConfirm={confirmDelete}
                title="Delete brand"
                description={
                    deletingBrand
                        ? `This permanently removes "${deletingBrand.name}" from the brand list.`
                        : undefined
                }
                confirmLabel="Delete"
                processing={processing}
            />
        </>
    );
}
