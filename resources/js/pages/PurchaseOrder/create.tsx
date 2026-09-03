import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import PurchaseOrderController from '@/actions/App/Http/Controllers/PurchaseOrderController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option } from '@/types/catalog';
import type { PricedSkuOption } from '@/types/sales-purchasing';

type Props = {
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

let rowCounter = 0;

function newRow(): ItemRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', quantity: 1, unitCost: '' };
}

export default function PurchaseOrderCreate({
    supplierOptions,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [rows, setRows] = useState<ItemRow[]>([newRow()]);

    function updateRow(key: string, changes: Partial<ItemRow>) {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...changes } : row,
            ),
        );
    }

    const total = rows.reduce(
        (sum, row) => sum + row.quantity * (Number(row.unitCost) || 0),
        0,
    );

    return (
        <>
            <Head title="New purchase order" />

            <PageHeader
                eyebrow="Purchasing"
                title="New purchase order"
                description="Order stock from a supplier into a warehouse."
            />

            <SectionCard>
                <Form {...PurchaseOrderController.store.form()}>
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
                                            defaultValue=""
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a supplier
                                            </option>
                                            {supplierOptions.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
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
                                            defaultValue=""
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a warehouse
                                            </option>
                                            {warehouseOptions.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
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
                                                new Date()
                                                    .toISOString()
                                                    .split('T')[0]
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
                                            defaultValue={0}
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
                                />
                            </FormField>

                            <FormField label="Line items" error={errors.items}>
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
                                                onChange={(event) => {
                                                    const selected =
                                                        skuOptions.find(
                                                            (option) =>
                                                                String(
                                                                    option.id,
                                                                ) ===
                                                                event.target
                                                                    .value,
                                                        );
                                                    updateRow(row.key, {
                                                        skuId: event.target
                                                            .value,
                                                        unitCost: selected
                                                            ? String(
                                                                  selected.cost_price,
                                                              )
                                                            : row.unitCost,
                                                    });
                                                }}
                                                aria-label="SKU"
                                            >
                                                <option value="">
                                                    Select SKU
                                                </option>
                                                {skuOptions.map((option) => (
                                                    <option
                                                        key={option.id}
                                                        value={option.id}
                                                    >
                                                        {option.sku} —{' '}
                                                        {option.product_name}
                                                    </option>
                                                ))}
                                            </select>

                                            <input
                                                name={`items[${index}][quantity]`}
                                                type="number"
                                                min={1}
                                                className="form-control"
                                                style={{ maxWidth: '7rem' }}
                                                value={row.quantity}
                                                onChange={(event) =>
                                                    updateRow(row.key, {
                                                        quantity: Number(
                                                            event.target.value,
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
                                                style={{ maxWidth: '9rem' }}
                                                value={row.unitCost}
                                                onChange={(event) =>
                                                    updateRow(row.key, {
                                                        unitCost:
                                                            event.target.value,
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

                                    <p className="app-text-muted small mb-0">
                                        Subtotal: {total.toFixed(2)}
                                    </p>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-purchase-order-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create purchase order
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
