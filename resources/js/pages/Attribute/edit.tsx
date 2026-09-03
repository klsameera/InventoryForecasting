import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AttributeController from '@/actions/App/Http/Controllers/AttributeController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Attribute } from '@/types/catalog';

type Props = {
    id: number;
    attribute: Attribute;
};

type ValueRow = { key: string; id?: number; value?: string };

let rowCounter = 0;

function newRow(): ValueRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}` };
}

export default function AttributeEdit({ id, attribute }: Props) {
    const [rows, setRows] = useState<ValueRow[]>(() => {
        const existing = (attribute.values ?? []).map((value) => ({
            key: `existing-${value.id}`,
            id: value.id,
            value: value.value,
        }));

        return existing.length > 0 ? existing : [newRow()];
    });

    return (
        <>
            <Head title={`Edit ${attribute.name}`} />

            <PageHeader
                eyebrow="Catalog"
                title={`Edit ${attribute.name}`}
                description="Update this attribute and its values."
            />

            <SectionCard>
                <Form
                    {...AttributeController.update.form(id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            <FormField
                                id="name"
                                label="Name"
                                error={errors.name}
                                required
                            >
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    className="form-control"
                                    defaultValue={attribute.name}
                                    required
                                />
                            </FormField>

                            <FormField
                                id="code"
                                label="Code"
                                error={errors.code}
                                required
                            >
                                <input
                                    id="code"
                                    name="code"
                                    type="text"
                                    className="form-control"
                                    defaultValue={attribute.code}
                                    required
                                />
                            </FormField>

                            <FormField
                                id="data_type"
                                label="Data type"
                                error={errors.data_type}
                                required
                            >
                                <select
                                    id="data_type"
                                    name="data_type"
                                    className="form-select"
                                    defaultValue={attribute.data_type}
                                >
                                    <option value="select">Select</option>
                                    <option value="text">Text</option>
                                    <option value="number">Number</option>
                                    <option value="boolean">Boolean</option>
                                </select>
                            </FormField>

                            <FormField error={errors.forecast_relevant}>
                                <div className="form-check form-switch">
                                    <input
                                        type="hidden"
                                        name="forecast_relevant"
                                        value="0"
                                    />
                                    <input
                                        id="forecast_relevant"
                                        name="forecast_relevant"
                                        type="checkbox"
                                        className="form-check-input"
                                        value="1"
                                        defaultChecked={
                                            attribute.forecast_relevant
                                        }
                                    />
                                    <label
                                        className="form-check-label"
                                        htmlFor="forecast_relevant"
                                    >
                                        Forecast relevant
                                    </label>
                                </div>
                            </FormField>

                            <FormField label="Values">
                                <div className="app-stack">
                                    {rows.map((row, index) => (
                                        <div
                                            key={row.key}
                                            className="d-flex gap-2 align-items-start"
                                        >
                                            {row.id && (
                                                <input
                                                    type="hidden"
                                                    name={`values[${index}][id]`}
                                                    value={row.id}
                                                />
                                            )}
                                            <input
                                                type="hidden"
                                                name={`values[${index}][sort_order]`}
                                                value={index}
                                            />
                                            <input
                                                type="text"
                                                name={`values[${index}][value]`}
                                                className="form-control"
                                                defaultValue={row.value}
                                                placeholder="e.g. 42"
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-quiet-danger btn-icon"
                                                aria-label="Remove value"
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
                                        Add value
                                    </button>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="update-attribute-button"
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
