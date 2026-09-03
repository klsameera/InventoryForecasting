import { Form, Head } from '@inertiajs/react';
import BrandController from '@/actions/App/Http/Controllers/BrandController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';

export default function BrandCreate() {
    return (
        <>
            <Head title="New brand" />

            <PageHeader
                eyebrow="Catalog"
                title="New brand"
                description="Add a brand to tag products with."
            />

            <SectionCard>
                <Form {...BrandController.store.form()}>
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
                                    placeholder="Skechers"
                                    required
                                />
                            </FormField>

                            <FormField
                                id="code"
                                label="Code"
                                error={errors.code}
                                required
                                hint="A short unique identifier, e.g. BR-001."
                            >
                                <input
                                    id="code"
                                    name="code"
                                    type="text"
                                    className="form-control"
                                    placeholder="BR-001"
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

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-brand-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create brand
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
