import { Form, Head } from '@inertiajs/react';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option } from '@/types/catalog';

type Props = {
    categoryOptions: Option[];
    brandOptions: Option[];
};

export default function ProductCreate({
    categoryOptions,
    brandOptions,
}: Props) {
    return (
        <>
            <Head title="New product" />

            <PageHeader
                eyebrow="Catalog"
                title="New product"
                description="Simple products get a SKU directly; configurable products get variants first."
            />

            <SectionCard>
                <Form {...ProductController.store.form()}>
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
                                    placeholder="iPhone 16 Pro"
                                    required
                                />
                            </FormField>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="category_id"
                                        label="Category"
                                        error={errors.category_id}
                                        required
                                    >
                                        <select
                                            id="category_id"
                                            name="category_id"
                                            className="form-select"
                                            defaultValue=""
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a category
                                            </option>
                                            {categoryOptions.map((option) => (
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
                                        id="brand_id"
                                        label="Brand"
                                        error={errors.brand_id}
                                    >
                                        <select
                                            id="brand_id"
                                            name="brand_id"
                                            className="form-select"
                                            defaultValue=""
                                        >
                                            <option value="">No brand</option>
                                            {brandOptions.map((option) => (
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
                                label="Product type"
                                error={errors.product_type}
                                required
                            >
                                <div className="d-flex gap-4">
                                    <div className="form-check">
                                        <input
                                            id="product_type_simple"
                                            name="product_type"
                                            type="radio"
                                            className="form-check-input"
                                            value="simple"
                                            defaultChecked
                                        />
                                        <label
                                            className="form-check-label"
                                            htmlFor="product_type_simple"
                                        >
                                            Simple — one SKU
                                        </label>
                                    </div>
                                    <div className="form-check">
                                        <input
                                            id="product_type_configurable"
                                            name="product_type"
                                            type="radio"
                                            className="form-check-input"
                                            value="configurable"
                                        />
                                        <label
                                            className="form-check-label"
                                            htmlFor="product_type_configurable"
                                        >
                                            Configurable — multiple variants
                                        </label>
                                    </div>
                                </div>
                            </FormField>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="model_number"
                                        label="Model number"
                                        error={errors.model_number}
                                    >
                                        <input
                                            id="model_number"
                                            name="model_number"
                                            type="text"
                                            className="form-control"
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="model_year"
                                        label="Model year"
                                        error={errors.model_year}
                                    >
                                        <input
                                            id="model_year"
                                            name="model_year"
                                            type="number"
                                            className="form-control"
                                            min={1900}
                                            max={2100}
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="launch_date"
                                        label="Launch date"
                                        error={errors.launch_date}
                                    >
                                        <input
                                            id="launch_date"
                                            name="launch_date"
                                            type="date"
                                            className="form-control"
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="end_of_life_date"
                                        label="End-of-life date"
                                        error={errors.end_of_life_date}
                                    >
                                        <input
                                            id="end_of_life_date"
                                            name="end_of_life_date"
                                            type="date"
                                            className="form-control"
                                        />
                                    </FormField>
                                </div>
                            </div>

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
                                    data-test="create-product-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create product
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
