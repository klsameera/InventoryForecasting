import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SupplierController from '@/actions/App/Http/Controllers/SupplierController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { PricedSkuOption } from '@/types/sales-purchasing';

type Props = {
    skuOptions: PricedSkuOption[];
};

type SkuRow = {
    key: string;
    skuId: string;
    unitCost: string;
};

let rowCounter = 0;

function newRow(): SkuRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', unitCost: '' };
}

export default function SupplierCreate({ skuOptions }: Props) {
    const [rows, setRows] = useState<SkuRow[]>([]);

    function updateRow(key: string, changes: Partial<SkuRow>) {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...changes } : row,
            ),
        );
    }

    return (
        <>
            <Head title="New supplier" />

            <PageHeader
                eyebrow="Purchasing"
                title="New supplier"
                description="A vendor you can buy stock from."
            />

            <SectionCard>
                <Form {...SupplierController.store.form()}>
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
                                    defaultValue={7}
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
                                        defaultChecked
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
                                            <div className="row g-2 align-items-end">
                                                <div className="col-md-4">
                                                    <label className="form-label small">
                                                        SKU
                                                    </label>
                                                    <select
                                                        name={`supplier_skus[${index}][sku_id]`}
                                                        className="form-select"
                                                        value={row.skuId}
                                                        onChange={(event) => {
                                                            const selected =
                                                                skuOptions.find(
                                                                    (option) =>
                                                                        String(
                                                                            option.id,
                                                                        ) ===
                                                                        event
                                                                            .target
                                                                            .value,
                                                                );
                                                            updateRow(row.key, {
                                                                skuId: event
                                                                    .target
                                                                    .value,
                                                                unitCost:
                                                                    selected
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
                                                        defaultValue={1}
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
                                                        defaultValue={1}
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
                                    data-test="create-supplier-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create supplier
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
