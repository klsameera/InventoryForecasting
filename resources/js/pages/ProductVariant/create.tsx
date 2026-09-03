import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductVariantController from '@/actions/App/Http/Controllers/ProductVariantController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { AttributeOption, Option } from '@/types/catalog';

type Props = {
    productId: number | null;
    productOptions: Option[];
    attributeOptions: AttributeOption[];
};

type ValueRow = { key: string; attributeId: string; attributeValueId: string };

let rowCounter = 0;

function newRow(): ValueRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}`, attributeId: '', attributeValueId: '' };
}

export default function ProductVariantCreate({
    productId,
    productOptions,
    attributeOptions,
}: Props) {
    const [rows, setRows] = useState<ValueRow[]>([newRow()]);

    function updateRow(key: string, changes: Partial<ValueRow>) {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...changes } : row,
            ),
        );
    }

    return (
        <>
            <Head title="New variant" />

            <PageHeader
                eyebrow="Catalog"
                title="New variant"
                description="A specific attribute combination for a configurable product."
            />

            <SectionCard>
                <Form {...ProductVariantController.store.form()}>
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            <FormField
                                id="product_id"
                                label="Product"
                                error={errors.product_id}
                                required
                            >
                                <select
                                    id="product_id"
                                    name="product_id"
                                    className="form-select"
                                    defaultValue={productId ?? ''}
                                    required
                                >
                                    <option value="" disabled>
                                        Select a product
                                    </option>
                                    {productOptions.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <FormField
                                id="name"
                                label="Variant name"
                                error={errors.name}
                                required
                                hint="e.g. Black / 128GB"
                            >
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    className="form-control"
                                    required
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

                            <FormField label="Attribute values">
                                <div className="app-stack">
                                    {rows.map((row, index) => {
                                        const values =
                                            attributeOptions.find(
                                                (attribute) =>
                                                    String(attribute.id) ===
                                                    row.attributeId,
                                            )?.values ?? [];

                                        return (
                                            <div
                                                key={row.key}
                                                className="d-flex gap-2 align-items-start"
                                            >
                                                <select
                                                    name={`attribute_values[${index}][attribute_id]`}
                                                    className="form-select"
                                                    value={row.attributeId}
                                                    onChange={(event) =>
                                                        updateRow(row.key, {
                                                            attributeId:
                                                                event.target
                                                                    .value,
                                                            attributeValueId:
                                                                '',
                                                        })
                                                    }
                                                    aria-label="Attribute"
                                                >
                                                    <option value="">
                                                        Select attribute
                                                    </option>
                                                    {attributeOptions.map(
                                                        (attribute) => (
                                                            <option
                                                                key={
                                                                    attribute.id
                                                                }
                                                                value={
                                                                    attribute.id
                                                                }
                                                            >
                                                                {attribute.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>

                                                <select
                                                    name={`attribute_values[${index}][attribute_value_id]`}
                                                    className="form-select"
                                                    value={row.attributeValueId}
                                                    onChange={(event) =>
                                                        updateRow(row.key, {
                                                            attributeValueId:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    aria-label="Value"
                                                    disabled={
                                                        row.attributeId === ''
                                                    }
                                                >
                                                    <option value="">
                                                        Select value
                                                    </option>
                                                    {values.map((value) => (
                                                        <option
                                                            key={value.id}
                                                            value={value.id}
                                                        >
                                                            {value.value}
                                                        </option>
                                                    ))}
                                                </select>

                                                <button
                                                    type="button"
                                                    className="btn btn-quiet-danger btn-icon"
                                                    aria-label="Remove attribute value"
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
                                        );
                                    })}

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
                                        Add attribute value
                                    </button>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-variant-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create variant
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
