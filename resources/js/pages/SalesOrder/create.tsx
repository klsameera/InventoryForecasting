import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SalesOrderController from '@/actions/App/Http/Controllers/SalesOrderController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option } from '@/types/catalog';
import type { PricedSkuOption } from '@/types/sales-purchasing';

type Props = {
    warehouseOptions: Option[];
    skuOptions: PricedSkuOption[];
};

type ItemRow = {
    key: string;
    skuId: string;
    quantity: number;
    unitPrice: string;
};

let rowCounter = 0;

function newRow(): ItemRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', quantity: 1, unitPrice: '' };
}

export default function SalesOrderCreate({
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

    const subtotal = rows.reduce(
        (sum, row) => sum + row.quantity * (Number(row.unitPrice) || 0),
        0,
    );

    return (
        <>
            <Head title="New sales order" />

            <PageHeader
                eyebrow="Sales"
                title="New sales order"
                description="Record a sale to be fulfilled from a warehouse."
            />

            <SectionCard>
                <Form {...SalesOrderController.store.form()}>
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
                                            defaultValue={0}
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
                                                        unitPrice: selected
                                                            ? String(
                                                                  selected.selling_price,
                                                              )
                                                            : row.unitPrice,
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
                                                name={`items[${index}][unit_price]`}
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                className="form-control"
                                                style={{ maxWidth: '9rem' }}
                                                value={row.unitPrice}
                                                onChange={(event) =>
                                                    updateRow(row.key, {
                                                        unitPrice:
                                                            event.target.value,
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

                                    <p className="app-text-muted small mb-0">
                                        Subtotal: {subtotal.toFixed(2)}
                                    </p>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-sales-order-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create sales order
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
