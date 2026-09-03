import { Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import CategoryController from '@/actions/App/Http/Controllers/CategoryController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { create, edit, index } from '@/routes/category';
import type { Category } from '@/types/catalog';
import type { Paginated, TableSort } from '@/types/ui';

type Filters = {
    search?: string;
    status?: string;
    sort?: string;
    direction?: string;
};

type Props = {
    categories: Paginated<Category>;
    filters: Filters;
};

export default function CategoryIndex({ categories, filters }: Props) {
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

    const columns: Column<Category>[] = [
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
            key: 'parent',
            header: 'Parent',
            cell: (row) => row.parent?.name ?? '—',
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

    const deletingCategory = categories.data.find(
        (row) => row.id === deletingId,
    );

    function confirmDelete() {
        if (deletingId === null) {
            return;
        }

        setProcessing(true);

        router.delete(CategoryController.delete.url(deletingId), {
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
                title="Categories"
                description="Organize products into a category tree."
                actions={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New category
                    </Link>
                }
            />

            <DataTable
                columns={columns}
                rows={categories.data}
                rowKey={(row) => row.id}
                sort={sort}
                onSortChange={(next) =>
                    reload({
                        sort: next.column,
                        direction: next.direction,
                        page: 1,
                    })
                }
                pagination={categories}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No categories yet"
                emptyDescription="Add your first category to start organizing products."
                emptyAction={
                    <Link href={create()} className="btn btn-gradient">
                        <Plus aria-hidden="true" />
                        New category
                    </Link>
                }
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search categories…"
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
                title="Delete category"
                description={
                    deletingCategory
                        ? `This permanently removes "${deletingCategory.name}" from the category list. Categories with sub-categories or products can't be deleted.`
                        : undefined
                }
                confirmLabel="Delete"
                processing={processing}
            />
        </>
    );
}
