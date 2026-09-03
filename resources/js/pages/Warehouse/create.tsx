import { Form, Head } from '@inertiajs/react';
import WarehouseController from '@/actions/App/Http/Controllers/WarehouseController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';

export default function WarehouseCreate() {
    return (
        <>
            <Head title="New warehouse" />

            <PageHeader
                eyebrow="Catalog"
                title="New warehouse"
                description="Add a location that will hold inventory."
            />

            <SectionCard>
                <Form {...WarehouseController.store.form()}>
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
                                    placeholder="Colombo Warehouse"
                                    required
                                />
                            </FormField>

                            <FormField
                                id="code"
                                label="Code"
                                error={errors.code}
                                required
                                hint="A short unique identifier, e.g. WH-001."
                            >
                                <input
                                    id="code"
                                    name="code"
                                    type="text"
                                    className="form-control"
                                    placeholder="WH-001"
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

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-warehouse-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create warehouse
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
