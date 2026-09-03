import { Form, Head } from '@inertiajs/react';
import WarehouseController from '@/actions/App/Http/Controllers/WarehouseController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Warehouse } from '@/types/catalog';

type Props = {
    id: number;
    warehouse: Warehouse;
};

export default function WarehouseEdit({ id, warehouse }: Props) {
    return (
        <>
            <Head title={`Edit ${warehouse.name}`} />

            <PageHeader
                eyebrow="Catalog"
                title={`Edit ${warehouse.name}`}
                description="Update this warehouse's details."
            />

            <SectionCard>
                <Form
                    {...WarehouseController.update.form(id)}
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
                                    defaultValue={warehouse.name}
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
                                    defaultValue={warehouse.code}
                                    required
                                />
                            </FormField>

                            <FormField
                                id="address"
                                label="Address"
                                error={errors.address}
                            >
                                <textarea
                                    id="address"
                                    name="address"
                                    className="form-control"
                                    rows={3}
                                    defaultValue={warehouse.address ?? ''}
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
                                        defaultChecked={warehouse.status}
                                    />
                                    <label
                                        className="form-check-label"
                                        htmlFor="status"
                                    >
                                        Active
                                    </label>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="update-warehouse-button"
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
