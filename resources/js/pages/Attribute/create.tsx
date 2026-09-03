import { Form, Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AttributeController from '@/actions/App/Http/Controllers/AttributeController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';

type ValueRow = { key: string };

let rowCounter = 0;

function newRow(): ValueRow {
    rowCounter += 1;

    return { key: `new-${rowCounter}` };
}

export default function AttributeCreate() {
    const [rows, setRows] = useState<ValueRow[]>([newRow()]);

    return (
        <>
            <Head title="New attribute" />

            <PageHeader
                eyebrow="Catalog"
                title="New attribute"
                description="Define a property, e.g. Size or Color, and its possible values."
            />

            <SectionCard>
                <Form {...AttributeController.store.form()}>
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
                                    placeholder="Size"
                                    required
                                />
                            </FormField>

                            <FormField
                                id="code"
                                label="Code"
                                error={errors.code}
                                required
                                hint="A short unique identifier, e.g. ATTR-SIZE."
                            >
                                <input
                                    id="code"
                                    name="code"
                                    type="text"
                                    className="form-control"
                                    placeholder="ATTR-SIZE"
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
                                    defaultValue="select"
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
                                            <input
                                                type="hidden"
                                                name={`values[${index}][sort_order]`}
                                                value={index}
                                            />
                                            <input
                                                type="text"
                                                name={`values[${index}][value]`}
                                                className="form-control"
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
                                    data-test="create-attribute-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create attribute
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
