import { Form, Head, Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import PurchaseOrderController from '@/actions/App/Http/Controllers/PurchaseOrderController';
import ConfirmDialog from '@/components/confirm-dialog';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import { create as createGoodsReceipt } from '@/routes/goods-receipt';
import { index } from '@/routes/purchase-order';
import type { Option } from '@/types/catalog';
import type { PricedSkuOption, PurchaseOrder } from '@/types/sales-purchasing';
import type { StatusTone } from '@/types/ui';

type Props = {
    id: number;
    purchaseOrder: PurchaseOrder;
    supplierOptions: Option[];
    warehouseOptions: Option[];
    skuOptions: PricedSkuOption[];
};

type ItemRow = {
    key: string;
    skuId: string;
    quantity: number;
    unitCost: string;
};

const STATUS_TONES: Record<PurchaseOrder['status'], StatusTone> = {
    draft: 'secondary',
    approved: 'info',
    ordered: 'primary',
    partially_received: 'warning',
    received: 'success',
    cancelled: 'danger',
};

let rowCounter = 0;

function rowFromItem(
    item: NonNullable<PurchaseOrder['items']>[number],
): ItemRow {
    rowCounter += 1;

    return {
        key: `existing-${item.id}-${rowCounter}`,
        skuId: String(item.sku_id),
        quantity: item.quantity,
        unitCost: String(item.unit_cost),
    };
}

function newRow(): ItemRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', quantity: 1, unitCost: '' };
}

export default function PurchaseOrderEdit({
    id,
    purchaseOrder,
    supplierOptions,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [rows, setRows] = useState<ItemRow[]>(
        (purchaseOrder.items ?? []).map(rowFromItem),
    );
    const [actionProcessing, setActionProcessing] = useState(false);
    const [confirmingCancel, setConfirmingCancel] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const isDraft = purchaseOrder.status === 'draft';

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
            <Head title={purchaseOrder.po_number} />

            <PageHeader
                eyebrow="Purchasing"
                title={purchaseOrder.po_number}
                description={`Supplier: ${purchaseOrder.supplier?.name ?? '—'} · Warehouse: ${purchaseOrder.warehouse?.name ?? '—'}`}
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <StatusBadge
                            tone={STATUS_TONES[purchaseOrder.status]}
                            label={purchaseOrder.status_label}
                        />

                        {purchaseOrder.status === 'draft' && (
                            <button
                                type="button"
                                className="btn btn-surface"
                                disabled={actionProcessing}
                                onClick={() =>
                                    runAction(
                                        PurchaseOrderController.approve.url(id),
                                    )
                                }
                            >
                                Approve
                            </button>
                        )}

                        {purchaseOrder.status === 'approved' && (
                            <button
                                type="button"
                                className="btn btn-surface"
                                disabled={actionProcessing}
                                onClick={() =>
                                    runAction(
                                        PurchaseOrderController.markOrdered.url(
                                            id,
                                        ),
                                    )
                                }
                            >
                                Mark as ordered
                            </button>
                        )}

                        {(purchaseOrder.status === 'ordered' ||
                            purchaseOrder.status === 'partially_received') && (
                            <Link
                                href={createGoodsReceipt.url({
                                    query: { purchase_order_id: id },
                                })}
                                className="btn btn-gradient"
                            >
                                Receive goods
                            </Link>
                        )}

                        {['draft', 'approved', 'ordered'].includes(
                            purchaseOrder.status,
                        ) && (
                            <button
                                type="button"
                                className="btn btn-quiet-danger"
                                disabled={actionProcessing}
                                onClick={() => setConfirmingCancel(true)}
                            >
                                Cancel order
                            </button>
                        )}
                    </div>
                }
            />

            {isDraft ? (
                <SectionCard>
                    <Form
                        {...PurchaseOrderController.update.form(id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="app-stack">
                                <div className="row g-3">
                                    <div className="col-md-6">
                                        <FormField
                                            id="supplier_id"
                                            label="Supplier"
                                            error={errors.supplier_id}
                                            required
                                        >
                                            <select
                                                id="supplier_id"
                                                name="supplier_id"
                                                className="form-select"
                                                defaultValue={
                                                    purchaseOrder.supplier_id
                                                }
                                                required
                                            >
                                                {supplierOptions.map(
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
                                            id="warehouse_id"
                                            label="Receiving warehouse"
                                            error={errors.warehouse_id}
                                            required
                                        >
                                            <select
                                                id="warehouse_id"
                                                name="warehouse_id"
                                                className="form-select"
                                                defaultValue={
                                                    purchaseOrder.warehouse_id
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

                                    <div className="col-md-4">
                                        <FormField
                                            id="order_date"
                                            label="Order date"
                                            error={errors.order_date}
                                            required
                                        >
                                            <input
                                                id="order_date"
                                                name="order_date"
                                                type="date"
                                                className="form-control"
                                                defaultValue={
                                                    purchaseOrder.order_date
                                                }
                                                required
                                            />
                                        </FormField>
                                    </div>

                                    <div className="col-md-4">
                                        <FormField
                                            id="expected_date"
                                            label="Expected date"
                                            error={errors.expected_date}
                                            hint="Optional"
                                        >
                                            <input
                                                id="expected_date"
                                                name="expected_date"
                                                type="date"
                                                className="form-control"
                                                defaultValue={
                                                    purchaseOrder.expected_date ??
                                                    ''
                                                }
                                            />
                                        </FormField>
                                    </div>

                                    <div className="col-md-4">
                                        <FormField
                                            id="tax"
                                            label="Tax"
                                            error={errors.tax}
                                            hint="Optional"
                                        >
                                            <input
                                                id="tax"
                                                name="tax"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                className="form-control"
                                                defaultValue={purchaseOrder.tax}
                                            />
                                        </FormField>
                                    </div>
                                </div>

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
                                        defaultValue={purchaseOrder.notes ?? ''}
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
                                                                {option.sku} —{' '}
                                                                {
                                                                    option.product_name
                                                                }
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

                                                <input
                                                    name={`items[${index}][unit_cost]`}
                                                    type="number"
                                                    min={0}
                                                    step="0.01"
                                                    className="form-control"
                                                    style={{
                                                        maxWidth: '9rem',
                                                    }}
                                                    value={row.unitCost}
                                                    onChange={(event) =>
                                                        updateRow(row.key, {
                                                            unitCost:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    aria-label="Unit cost"
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
                                    <th className="text-end">Ordered</th>
                                    <th className="text-end">Received</th>
                                    <th className="text-end">Unit cost</th>
                                    <th className="text-end">Line total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(purchaseOrder.items ?? []).map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.sku ?? '—'}</td>
                                        <td>{item.product_name ?? '—'}</td>
                                        <td className="text-end">
                                            {item.quantity}
                                        </td>
                                        <td className="text-end">
                                            {item.received_qty}
                                        </td>
                                        <td className="text-end">
                                            {item.unit_cost.toFixed(2)}
                                        </td>
                                        <td className="text-end">
                                            {item.line_total.toFixed(2)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="app-text-muted mb-0">
                        Subtotal {purchaseOrder.subtotal.toFixed(2)} + tax{' '}
                        {purchaseOrder.tax.toFixed(2)} = total{' '}
                        <strong>{purchaseOrder.total.toFixed(2)}</strong>
                    </p>
                </SectionCard>
            )}

            <ConfirmDialog
                open={confirmingCancel}
                onCancel={() => setConfirmingCancel(false)}
                onConfirm={() => {
                    setConfirmingCancel(false);
                    runAction(PurchaseOrderController.cancel.url(id));
                }}
                title="Cancel purchase order"
                description={`This cancels ${purchaseOrder.po_number}. It cannot be reopened.`}
                confirmLabel="Cancel order"
                processing={actionProcessing}
            />

            <ConfirmDialog
                open={confirmingDelete}
                onCancel={() => setConfirmingDelete(false)}
                onConfirm={() => {
                    router.delete(PurchaseOrderController.delete.url(id), {
                        onSuccess: () => router.visit(index.url()),
                    });
                }}
                title="Delete purchase order"
                description={`This permanently removes ${purchaseOrder.po_number}.`}
                confirmLabel="Delete"
            />
        </>
    );
}
