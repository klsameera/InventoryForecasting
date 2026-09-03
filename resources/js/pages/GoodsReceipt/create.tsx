import { Form, Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import GoodsReceiptController from '@/actions/App/Http/Controllers/GoodsReceiptController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import { create } from '@/routes/goods-receipt';
import type {
    PurchaseOrder,
    PurchaseOrderOption,
} from '@/types/sales-purchasing';

type Props = {
    purchaseOrderOptions: PurchaseOrderOption[];
    purchaseOrder: PurchaseOrder | null;
};

export default function GoodsReceiptCreate({
    purchaseOrderOptions,
    purchaseOrder,
}: Props) {
    const outstandingItems = useMemo(
        () =>
            (purchaseOrder?.items ?? []).filter(
                (item) => item.received_qty < item.quantity,
            ),
        [purchaseOrder],
    );

    return (
        <>
            <Head title="Record goods receipt" />

            <PageHeader
                eyebrow="Purchasing"
                title="Record goods receipt"
                description="Log what arrived against an ordered purchase order."
            />

            <SectionCard>
                <div className="app-stack">
                    <FormField
                        id="purchase_order_picker"
                        label="Purchase order"
                    >
                        <select
                            id="purchase_order_picker"
                            className="form-select"
                            value={purchaseOrder?.id ?? ''}
                            onChange={(event) =>
                                router.get(create.url(), {
                                    purchase_order_id:
                                        event.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Select a purchase order</option>
                            {purchaseOrderOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.po_number} — {option.supplier_name}
                                </option>
                            ))}
                        </select>
                    </FormField>

                    {purchaseOrder && outstandingItems.length === 0 && (
                        <p className="app-text-muted">
                            Every line on {purchaseOrder.po_number} has already
                            been received in full.
                        </p>
                    )}

                    {purchaseOrder && outstandingItems.length > 0 && (
                        <Form
                            {...GoodsReceiptController.store.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="app-stack">
                                    <input
                                        type="hidden"
                                        name="purchase_order_id"
                                        value={purchaseOrder.id}
                                    />
                                    <input
                                        type="hidden"
                                        name="warehouse_id"
                                        value={purchaseOrder.warehouse_id}
                                    />

                                    <FormField
                                        id="received_date"
                                        label="Received date"
                                        error={errors.received_date}
                                        required
                                    >
                                        <input
                                            id="received_date"
                                            name="received_date"
                                            type="date"
                                            className="form-control"
                                            defaultValue={
                                                new Date()
                                                    .toISOString()
                                                    .split('T')[0]
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
                                        />
                                    </FormField>

                                    <FormField
                                        label="Received lines"
                                        error={errors.items}
                                    >
                                        <div className="table-responsive">
                                            <table className="table app-table">
                                                <thead>
                                                    <tr>
                                                        <th>SKU</th>
                                                        <th>Product</th>
                                                        <th className="text-end">
                                                            Outstanding
                                                        </th>
                                                        <th
                                                            style={{
                                                                width: '9rem',
                                                            }}
                                                        >
                                                            Receiving now
                                                        </th>
                                                        <th
                                                            style={{
                                                                width: '9rem',
                                                            }}
                                                        >
                                                            Unit cost
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {outstandingItems.map(
                                                        (item, index) => {
                                                            const outstanding =
                                                                item.quantity -
                                                                item.received_qty;

                                                            return (
                                                                <tr
                                                                    key={
                                                                        item.id
                                                                    }
                                                                >
                                                                    <td>
                                                                        <input
                                                                            type="hidden"
                                                                            name={`items[${index}][purchase_order_item_id]`}
                                                                            value={
                                                                                item.id
                                                                            }
                                                                        />
                                                                        <input
                                                                            type="hidden"
                                                                            name={`items[${index}][sku_id]`}
                                                                            value={
                                                                                item.sku_id
                                                                            }
                                                                        />
                                                                        {item.sku ??
                                                                            '—'}
                                                                    </td>
                                                                    <td>
                                                                        {item.product_name ??
                                                                            '—'}
                                                                    </td>
                                                                    <td className="text-end">
                                                                        {
                                                                            outstanding
                                                                        }
                                                                    </td>
                                                                    <td>
                                                                        <input
                                                                            name={`items[${index}][received_qty]`}
                                                                            type="number"
                                                                            min={
                                                                                0
                                                                            }
                                                                            max={
                                                                                outstanding
                                                                            }
                                                                            className="form-control"
                                                                            defaultValue={
                                                                                outstanding
                                                                            }
                                                                            aria-label={`Received quantity for ${item.sku}`}
                                                                        />
                                                                    </td>
                                                                    <td>
                                                                        <input
                                                                            name={`items[${index}][unit_cost]`}
                                                                            type="number"
                                                                            min={
                                                                                0
                                                                            }
                                                                            step="0.01"
                                                                            className="form-control"
                                                                            defaultValue={
                                                                                item.unit_cost
                                                                            }
                                                                            aria-label={`Unit cost for ${item.sku}`}
                                                                        />
                                                                    </td>
                                                                </tr>
                                                            );
                                                        },
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </FormField>

                                    <div className="app-form-actions">
                                        <button
                                            type="submit"
                                            className="btn btn-gradient"
                                            disabled={processing}
                                            data-test="create-goods-receipt-button"
                                        >
                                            {processing && (
                                                <Spinner size="sm" />
                                            )}
                                            Record receipt
                                        </button>
                                    </div>
                                </div>
                            )}
                        </Form>
                    )}
                </div>
            </SectionCard>
        </>
    );
}
