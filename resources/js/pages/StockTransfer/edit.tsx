import { Form, Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import StockTransferController from '@/actions/App/Http/Controllers/StockTransferController';
import ConfirmDialog from '@/components/confirm-dialog';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import { index } from '@/routes/stock-transfer';
import type { Option, SkuOption } from '@/types/catalog';
import type { StockTransfer } from '@/types/sales-purchasing';
import type { StatusTone } from '@/types/ui';

type Props = {
    id: number;
    stockTransfer: StockTransfer;
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

type ItemRow = { key: string; skuId: string; quantity: number };

const STATUS_TONES: Record<StockTransfer['status'], StatusTone> = {
    draft: 'secondary',
    approved: 'info',
    dispatched: 'warning',
    received: 'success',
    cancelled: 'danger',
};

let rowCounter = 0;

function rowFromItem(
    item: NonNullable<StockTransfer['items']>[number],
): ItemRow {
    rowCounter += 1;

    return {
        key: `existing-${item.id}-${rowCounter}`,
        skuId: String(item.sku_id),
        quantity: item.quantity,
    };
}

function newRow(): ItemRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', quantity: 1 };
}

export default function StockTransferEdit({
    id,
    stockTransfer,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [rows, setRows] = useState<ItemRow[]>(
        (stockTransfer.items ?? []).map(rowFromItem),
    );
    const [actionProcessing, setActionProcessing] = useState(false);
    const [confirmingCancel, setConfirmingCancel] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const isDraft = stockTransfer.status === 'draft';

    function updateRow(key: string, changes: Partial<ItemRow>) {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...changes } : row,
            ),
        );
    }

    function runAction(url: string) {
        setActionProcessing(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setActionProcessing(false),
            },
        );
    }

    return (
        <>
            <Head title={stockTransfer.transfer_number} />

            <PageHeader
                eyebrow="Inventory"
                title={stockTransfer.transfer_number}
                description={`${stockTransfer.source_warehouse?.name ?? '—'} → ${stockTransfer.destination_warehouse?.name ?? '—'}`}
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <StatusBadge
                            tone={STATUS_TONES[stockTransfer.status]}
                            label={stockTransfer.status_label}
                        />

                        {stockTransfer.status === 'draft' && (
                            <button
                                type="button"
                                className="btn btn-surface"
                                disabled={actionProcessing}
                                onClick={() =>
                                    runAction(
                                        StockTransferController.approve.url(id),
                                    )
                                }
                            >
                                Approve
                            </button>
                        )}

                        {stockTransfer.status === 'approved' && (
                            <button
                                type="button"
                                className="btn btn-surface"
                                disabled={actionProcessing}
                                onClick={() =>
                                    runAction(
                                        StockTransferController.dispatch.url(
                                            id,
                                        ),
                                    )
                                }
                            >
                                Dispatch
                            </button>
                        )}

                        {stockTransfer.status === 'dispatched' && (
                            <button
                                type="button"
                                className="btn btn-gradient"
                                disabled={actionProcessing}
                                onClick={() =>
                                    runAction(
                                        StockTransferController.receive.url(id),
                                    )
                                }
                            >
                                Receive
                            </button>
                        )}

                        {['draft', 'approved'].includes(
                            stockTransfer.status,
                        ) && (
                            <button
                                type="button"
                                className="btn btn-quiet-danger"
                                disabled={actionProcessing}
                                onClick={() => setConfirmingCancel(true)}
                            >
                                Cancel transfer
                            </button>
                        )}
                    </div>
                }
            />

            {isDraft ? (
                <SectionCard>
                    <Form
                        {...StockTransferController.update.form(id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="app-stack">
                                <div className="row g-3">
                                    <div className="col-md-6">
                                        <FormField
                                            id="source_warehouse_id"
                                            label="Source warehouse"
                                            error={errors.source_warehouse_id}
                                            required
                                        >
                                            <select
                                                id="source_warehouse_id"
                                                name="source_warehouse_id"
                                                className="form-select"
                                                defaultValue={
                                                    stockTransfer.source_warehouse_id
                                                }
                                                required
                                            >
                                                {warehouseOptions.map(
                                                    (option) => (
                                                        <option
                                                            key={option.id}
                                                            value={option.id}
                                                        >
                                                            {option.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </FormField>
                                    </div>

                                    <div className="col-md-6">
                                        <FormField
                                            id="destination_warehouse_id"
                                            label="Destination warehouse"
                                            error={
                                                errors.destination_warehouse_id
                                            }
                                            required
                                        >
                                            <select
                                                id="destination_warehouse_id"
                                                name="destination_warehouse_id"
                                                className="form-select"
                                                defaultValue={
                                                    stockTransfer.destination_warehouse_id
                                                }
                                                required
                                            >
                                                {warehouseOptions.map(
                                                    (option) => (
                                                        <option
                                                            key={option.id}
                                                            value={option.id}
                                                        >
                                                            {option.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </FormField>
                                    </div>
                                </div>

                                <FormField
                                    id="transfer_date"
                                    label="Transfer date"
                                    error={errors.transfer_date}
                                    required
                                >
                                    <input
                                        id="transfer_date"
                                        name="transfer_date"
                                        type="date"
                                        className="form-control"
                                        defaultValue={
                                            stockTransfer.transfer_date
                                        }
                                        required
                                    />
                                </FormField>

                                <FormField
                                    id="notes"
                                    label="Notes"
                                    error={errors.notes}
                                    hint="Optional"
                                >
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        className="form-control"
                                        rows={2}
                                        defaultValue={stockTransfer.notes ?? ''}
                                    />
                                </FormField>

                                <FormField
                                    label="Line items"
                                    error={errors.items}
                                >
                                    <div className="app-stack">
                                        {rows.map((row, index) => (
                                            <div
                                                key={row.key}
                                                className="d-flex gap-2 align-items-start"
                                            >
                                                <select
                                                    name={`items[${index}][sku_id]`}
                                                    className="form-select"
                                                    value={row.skuId}
                                                    onChange={(event) =>
                                                        updateRow(row.key, {
                                                            skuId: event.target
                                                                .value,
                                                        })
                                                    }
                                                    aria-label="SKU"
                                                >
                                                    <option value="">
                                                        Select SKU
                                                    </option>
                                                    {skuOptions.map(
                                                        (option) => (
                                                            <option
                                                                key={option.id}
                                                                value={
                                                                    option.id
                                                                }
                                                            >
                                                                {option.sku}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>

                                                <input
                                                    name={`items[${index}][quantity]`}
                                                    type="number"
                                                    min={1}
                                                    className="form-control"
                                                    style={{
                                                        maxWidth: '7rem',
                                                    }}
                                                    value={row.quantity}
                                                    onChange={(event) =>
                                                        updateRow(row.key, {
                                                            quantity: Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        })
                                                    }
                                                    aria-label="Quantity"
                                                />

                                                <button
                                                    type="button"
                                                    className="btn btn-quiet-danger btn-icon"
                                                    aria-label="Remove line item"
                                                    disabled={rows.length === 1}
                                                    onClick={() =>
                                                        setRows((current) =>
                                                            current.filter(
                                                                (item) =>
                                                                    item.key !==
                                                                    row.key,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </button>
                                            </div>
                                        ))}

                                        <button
                                            type="button"
                                            className="btn btn-surface btn-sm align-self-start"
                                            onClick={() =>
                                                setRows((current) => [
                                                    ...current,
                                                    newRow(),
                                                ])
                                            }
                                        >
                                            <Plus aria-hidden="true" />
                                            Add line item
                                        </button>
                                    </div>
                                </FormField>

                                <div className="app-form-actions">
                                    <button
                                        type="submit"
                                        className="btn btn-gradient"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner size="sm" />}
                                        Save changes
                                    </button>

                                    <button
                                        type="button"
                                        className="btn btn-quiet-danger"
                                        onClick={() =>
                                            setConfirmingDelete(true)
                                        }
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        )}
                    </Form>
                </SectionCard>
            ) : (
                <SectionCard title="Line items">
                    <div className="table-responsive">
                        <table className="table app-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Product</th>
                                    <th className="text-end">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(stockTransfer.items ?? []).map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.sku ?? '—'}</td>
                                        <td>{item.product_name ?? '—'}</td>
                                        <td className="text-end">
                                            {item.quantity}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>
            )}

            <ConfirmDialog
                open={confirmingCancel}
                onCancel={() => setConfirmingCancel(false)}
                onConfirm={() => {
                    setConfirmingCancel(false);
                    runAction(StockTransferController.cancel.url(id));
                }}
                title="Cancel stock transfer"
                description={`This cancels ${stockTransfer.transfer_number}. It cannot be reopened.`}
                confirmLabel="Cancel transfer"
                processing={actionProcessing}
            />

            <ConfirmDialog
                open={confirmingDelete}
                onCancel={() => setConfirmingDelete(false)}
                onConfirm={() => {
                    router.delete(StockTransferController.delete.url(id), {
                        onSuccess: () => router.visit(index.url()),
                    });
                }}
                title="Delete stock transfer"
                description={`This permanently removes ${stockTransfer.transfer_number}.`}
                confirmLabel="Delete"
            />
        </>
    );
}
