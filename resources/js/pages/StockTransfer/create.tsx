import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import StockTransferController from '@/actions/App/Http/Controllers/StockTransferController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option, SkuOption } from '@/types/catalog';

type Props = {
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

type ItemRow = { key: string; skuId: string; quantity: number };

let rowCounter = 0;

function newRow(): ItemRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, skuId: '', quantity: 1 };
}

export default function StockTransferCreate({
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

    return (
        <>
            <Head title="New stock transfer" />

            <PageHeader
                eyebrow="Inventory"
                title="New stock transfer"
                description="Move stock from one warehouse to another."
            />

            <SectionCard>
                <Form {...StockTransferController.store.form()}>
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
                                        id="destination_warehouse_id"
                                        label="Destination warehouse"
                                        error={errors.destination_warehouse_id}
                                        required
                                    >
                                        <select
                                            id="destination_warehouse_id"
                                            name="destination_warehouse_id"
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
                                        new Date().toISOString().split('T')[0]
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
                                                {skuOptions.map((option) => (
                                                    <option
                                                        key={option.id}
                                                        value={option.id}
                                                    >
                                                        {option.sku}
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
                                    data-test="create-stock-transfer-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create transfer
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
