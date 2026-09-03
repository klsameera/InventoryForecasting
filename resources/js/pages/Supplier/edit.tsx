import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SupplierController from '@/actions/App/Http/Controllers/SupplierController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { PricedSkuOption, Supplier } from '@/types/sales-purchasing';

type Props = {
    id: number;
    supplier: Supplier;
    skuOptions: PricedSkuOption[];
};

type SkuRow = {
    key: string;
    id: number | null;
    skuId: string;
    unitCost: string;
    supplierSku: string;
    minimumOrderQty: number;
    orderMultiple: number;
    leadTime: number | '';
    isPrimary: boolean;
};

let rowCounter = 0;

function newRow(): SkuRow {
    rowCounter += 1;

    return {
        key: `new-${rowCounter}`,
        id: null,
        skuId: '',
        unitCost: '',
        supplierSku: '',
        minimumOrderQty: 1,
        orderMultiple: 1,
        leadTime: '',
        isPrimary: false,
    };
}

export default function SupplierEdit({ id, supplier, skuOptions }: Props) {
    const [rows, setRows] = useState<SkuRow[]>(
        (supplier.supplier_skus ?? []).map((row) => ({
            key: `existing-${row.id}`,
            id: row.id,
            skuId: String(row.sku_id),
            unitCost: String(row.unit_cost),
            supplierSku: row.supplier_sku ?? '',
            minimumOrderQty: row.minimum_order_qty,
            orderMultiple: row.order_multiple,
            leadTime: row.expected_lead_time_days ?? '',
            isPrimary: row.is_primary,
        })),
    );

    function updateRow(key: string, changes: Partial<SkuRow>) {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...changes } : row,
            ),
        );
    }

    return (
        <>
            <Head title={`Edit ${supplier.name}`} />

            <PageHeader
                eyebrow="Purchasing"
                title={`Edit ${supplier.name}`}
                description="Update this supplier's details and the SKUs they supply."
            />

            <SectionCard>
                <Form
                    {...SupplierController.update.form(id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            <FormField
                                id="name"
                                label="Supplier name"
                                error={errors.name}
                                required
                            >
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    className="form-control"
                                    defaultValue={supplier.name}
                                    required
                                />
                            </FormField>

                            <FormField
                                id="default_lead_time_days"
                                label="Default lead time (days)"
                                error={errors.default_lead_time_days}
                                required
                            >
                                <input
                                    id="default_lead_time_days"
                                    name="default_lead_time_days"
                                    type="number"
                                    min={0}
                                    className="form-control"
                                    defaultValue={
                                        supplier.default_lead_time_days
                                    }
                                    required
                                />
                            </FormField>

                            <FormField
                                id="minimum_order_value"
                                label="Minimum order value"
                                error={errors.minimum_order_value}
                                hint="Optional"
                            >
                                <input
                                    id="minimum_order_value"
                                    name="minimum_order_value"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    className="form-control"
                                    defaultValue={
                                        supplier.minimum_order_value ?? ''
                                    }
                                />
                            </FormField>

                            <FormField error={errors.status}>
                                <div className="form-check form-switch">
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="0"
                                    />
                                    <input
                                        id="status"
                                        name="status"
                                        type="checkbox"
                                        className="form-check-input"
                                        value="1"
                                        defaultChecked={supplier.status}
                                    />
                                    <label
                                        className="form-check-label"
                                        htmlFor="status"
                                    >
                                        Active
                                    </label>
                                </div>
                            </FormField>

                            <FormField label="SKUs supplied">
                                <div className="app-stack">
                                    {rows.map((row, index) => (
                                        <div
                                            key={row.key}
                                            className="app-card p-3"
                                        >
                                            {row.id !== null && (
                                                <input
                                                    type="hidden"
                                                    name={`supplier_skus[${index}][id]`}
                                                    value={row.id}
                                                />
                                            )}

                                            <div className="row g-2 align-items-end">
                                                <div className="col-md-4">
                                                    <label className="form-label small">
                                                        SKU
                                                    </label>
                                                    <select
                                                        name={`supplier_skus[${index}][sku_id]`}
                                                        className="form-select"
                                                        value={row.skuId}
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                skuId: event
                                                                    .target
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
                                                                    key={
                                                                        option.id
                                                                    }
                                                                    value={
                                                                        option.id
                                                                    }
                                                                >
                                                                    {option.sku}{' '}
                                                                    —{' '}
                                                                    {
                                                                        option.product_name
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>

                                                <div className="col-md-3">
                                                    <label className="form-label small">
                                                        Supplier SKU
                                                    </label>
                                                    <input
                                                        name={`supplier_skus[${index}][supplier_sku]`}
                                                        type="text"
                                                        className="form-control"
                                                        value={row.supplierSku}
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                supplierSku:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                </div>

                                                <div className="col-md-2">
                                                    <label className="form-label small">
                                                        Unit cost
                                                    </label>
                                                    <input
                                                        name={`supplier_skus[${index}][unit_cost]`}
                                                        type="number"
                                                        min={0}
                                                        step="0.01"
                                                        className="form-control"
                                                        value={row.unitCost}
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                unitCost:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                </div>

                                                <div className="col-md-2">
                                                    <label className="form-label small">
                                                        MOQ
                                                    </label>
                                                    <input
                                                        name={`supplier_skus[${index}][minimum_order_qty]`}
                                                        type="number"
                                                        min={1}
                                                        className="form-control"
                                                        value={
                                                            row.minimumOrderQty
                                                        }
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                minimumOrderQty:
                                                                    Number(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    ),
                                                            })
                                                        }
                                                    />
                                                </div>

                                                <div className="col-md-1 d-flex justify-content-end">
                                                    <button
                                                        type="button"
                                                        className="btn btn-quiet-danger btn-icon"
                                                        aria-label="Remove SKU"
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
                                            </div>

                                            <div className="row g-2 align-items-end mt-1">
                                                <div className="col-md-3">
                                                    <label className="form-label small">
                                                        Order multiple
                                                    </label>
                                                    <input
                                                        name={`supplier_skus[${index}][order_multiple]`}
                                                        type="number"
                                                        min={1}
                                                        className="form-control"
                                                        value={
                                                            row.orderMultiple
                                                        }
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                orderMultiple:
                                                                    Number(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    ),
                                                            })
                                                        }
                                                    />
                                                </div>

                                                <div className="col-md-3">
                                                    <label className="form-label small">
                                                        Lead time (days)
                                                    </label>
                                                    <input
                                                        name={`supplier_skus[${index}][expected_lead_time_days]`}
                                                        type="number"
                                                        min={0}
                                                        className="form-control"
                                                        value={row.leadTime}
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                leadTime:
                                                                    event.target
                                                                        .value ===
                                                                    ''
                                                                        ? ''
                                                                        : Number(
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                            })
                                                        }
                                                    />
                                                </div>

                                                <div className="col-md-3 d-flex align-items-center">
                                                    <div className="form-check mt-3">
                                                        <input
                                                            id={`primary-${row.key}`}
                                                            name={`supplier_skus[${index}][is_primary]`}
                                                            type="checkbox"
                                                            className="form-check-input"
                                                            value="1"
                                                            checked={
                                                                row.isPrimary
                                                            }
                                                            onChange={(event) =>
                                                                updateRow(
                                                                    row.key,
                                                                    {
                                                                        isPrimary:
                                                                            event
                                                                                .target
                                                                                .checked,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        <label
                                                            className="form-check-label"
                                                            htmlFor={`primary-${row.key}`}
                                                        >
                                                            Primary supplier
                                                        </label>
                                                    </div>
                                                </div>

                                                <input
                                                    type="hidden"
                                                    name={`supplier_skus[${index}][status]`}
                                                    value="1"
                                                />
                                            </div>
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
                                        Add SKU
                                    </button>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="update-supplier-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Save changes
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
