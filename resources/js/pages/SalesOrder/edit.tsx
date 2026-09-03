import { Form, Head, Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SalesOrderController from '@/actions/App/Http/Controllers/SalesOrderController';
import ConfirmDialog from '@/components/confirm-dialog';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import { index } from '@/routes/sales-order';
import { create as createSalesReturn } from '@/routes/sales-return';
import type { Option } from '@/types/catalog';
import type { PricedSkuOption, SalesOrder } from '@/types/sales-purchasing';
import type { StatusTone } from '@/types/ui';

type Props = {
    id: number;
    salesOrder: SalesOrder;
    warehouseOptions: Option[];
    skuOptions: PricedSkuOption[];
};

type ItemRow = {
    key: string;
    skuId: string;
    quantity: number;
    unitPrice: string;
};

const STATUS_TONES: Record<SalesOrder['status'], StatusTone> = {
    draft: 'secondary',
    confirmed: 'success',
    cancelled: 'danger',
};

let rowCounter = 0;

function rowFromItem(item: NonNullable<SalesOrder['items']>[number]): ItemRow {
    rowCounter += 1;

    return {
        key: `existing-${item.id}-${rowCounter}`,
        skuId: String(item.sku_id),
        quantity: item.quantity,
        unitPrice: String(item.unit_price),
    };
}

function newRow(): ItemRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', quantity: 1, unitPrice: '' };
}

export default function SalesOrderEdit({
    id,
    salesOrder,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [rows, setRows] = useState<ItemRow[]>(
        (salesOrder.items ?? []).map(rowFromItem),
    );
    const [actionProcessing, setActionProcessing] = useState(false);
    const [confirmingCancel, setConfirmingCancel] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const isDraft = salesOrder.status === 'draft';

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
            <Head title={salesOrder.order_number} />

            <PageHeader
                eyebrow="Sales"
                title={salesOrder.order_number}
                description={`Warehouse: ${salesOrder.warehouse?.name ?? '—'}${salesOrder.customer_name ? ` · Customer: ${salesOrder.customer_name}` : ''}`}
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <StatusBadge
                            tone={STATUS_TONES[salesOrder.status]}
                            label={salesOrder.status_label}
                        />

                        {salesOrder.status === 'draft' && (
                            <button
                                type="button"
                                className="btn btn-gradient"
                                disabled={actionProcessing}
                                onClick={() =>
                                    runAction(
                                        SalesOrderController.confirm.url(id),
                                    )
                                }
                            >
                                Confirm
                            </button>
                        )}

                        {salesOrder.status === 'confirmed' && (
                            <Link
                                href={createSalesReturn.url({
                                    query: { sales_order_id: id },
                                })}
                                className="btn btn-surface"
                            >
                                Record return
                            </Link>
                        )}

                        {salesOrder.status === 'draft' && (
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
                        {...SalesOrderController.update.form(id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="app-stack">
                                <div className="row g-3">
                                    <div className="col-md-6">
                                        <FormField
                                            id="warehouse_id"
                                            label="Fulfilling warehouse"
                                            error={errors.warehouse_id}
                                            required
                                        >
                                            <select
                                                id="warehouse_id"
                                                name="warehouse_id"
                                                className="form-select"
                                                defaultValue={
                                                    salesOrder.warehouse_id
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
                                            id="customer_name"
                                            label="Customer"
                                            error={errors.customer_name}
                                            hint="Optional"
                                        >
                                            <input
                                                id="customer_name"
                                                name="customer_name"
                                                type="text"
                                                className="form-control"
                                                defaultValue={
                                                    salesOrder.customer_name ??
                                                    ''
                                                }
                                            />
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
                                                    salesOrder.order_date
                                                }
                                                required
                                            />
                                        </FormField>
                                    </div>

                                    <div className="col-md-4">
                                        <FormField
                                            id="discount"
                                            label="Order discount"
                                            error={errors.discount}
                                            hint="Optional"
                                        >
                                            <input
                                                id="discount"
                                                name="discount"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                className="form-control"
                                                defaultValue={
                                                    salesOrder.discount
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
                                                defaultValue={salesOrder.tax}
                                            />
                                        </FormField>
                                    </div>
                                </div>

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
                                                    name={`items[${index}][unit_price]`}
                                                    type="number"
                                                    min={0}
                                                    step="0.01"
                                                    className="form-control"
                                                    style={{
                                                        maxWidth: '9rem',
                                                    }}
                                                    value={row.unitPrice}
                                                    onChange={(event) =>
                                                        updateRow(row.key, {
                                                            unitPrice:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    aria-label="Unit price"
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
                                    <th className="text-end">Unit price</th>
                                    <th className="text-end">Net amount</th>
                                    <th className="text-end">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(salesOrder.items ?? []).map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.sku ?? '—'}</td>
                                        <td>{item.product_name ?? '—'}</td>
                                        <td className="text-end">
                                            {item.quantity}
                                        </td>
                                        <td className="text-end">
                                            {item.unit_price.toFixed(2)}
                                        </td>
                                        <td className="text-end">
                                            {item.net_amount.toFixed(2)}
                                        </td>
                                        <td className="text-end">
                                            {item.cost !== null
                                                ? item.cost.toFixed(2)
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="app-text-muted mb-0">
                        Subtotal {salesOrder.subtotal.toFixed(2)} − discount{' '}
                        {salesOrder.discount.toFixed(2)} + tax{' '}
                        {salesOrder.tax.toFixed(2)} = total{' '}
                        <strong>{salesOrder.total.toFixed(2)}</strong>
                    </p>
                </SectionCard>
            )}

            <ConfirmDialog
                open={confirmingCancel}
                onCancel={() => setConfirmingCancel(false)}
                onConfirm={() => {
                    setConfirmingCancel(false);
                    runAction(SalesOrderController.cancel.url(id));
                }}
                title="Cancel sales order"
                description={`This cancels ${salesOrder.order_number}. It cannot be reopened.`}
                confirmLabel="Cancel order"
                processing={actionProcessing}
            />

            <ConfirmDialog
                open={confirmingDelete}
                onCancel={() => setConfirmingDelete(false)}
                onConfirm={() => {
                    router.delete(SalesOrderController.delete.url(id), {
                        onSuccess: () => router.visit(index.url()),
                    });
                }}
                title="Delete sales order"
                description={`This permanently removes ${salesOrder.order_number}.`}
                confirmLabel="Delete"
            />
        </>
    );
}
