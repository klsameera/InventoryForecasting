import { Form, Head } from '@inertiajs/react';
import BrandController from '@/actions/App/Http/Controllers/BrandController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Brand } from '@/types/catalog';

type Props = {
    id: number;
    brand: Brand;
};

export default function BrandEdit({ id, brand }: Props) {
    return (
        <>
            <Head title={`Edit ${brand.name}`} />

            <PageHeader
                eyebrow="Catalog"
                title={`Edit ${brand.name}`}
                description="Update this brand's details."
            />

            <SectionCard>
                <Form
                    {...BrandController.update.form(id)}
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
                                    defaultValue={brand.name}
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
                                    defaultValue={brand.code}
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
                                        defaultChecked={brand.status}
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
                                    data-test="update-brand-button"
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
