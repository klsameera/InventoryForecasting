import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import SkuController from '@/actions/App/Http/Controllers/SkuController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option, VariantOption } from '@/types/catalog';

type Props = {
    productId: number | null;
    productOptions: Option[];
    variantOptions: VariantOption[];
};

export default function SkuCreate({
    productId,
    productOptions,
    variantOptions,
}: Props) {
    const [selectedProductId, setSelectedProductId] = useState(
        String(productId ?? productOptions[0]?.id ?? ''),
    );

    const variantsForProduct = variantOptions.filter(
        (variant) => variant.product_id === Number(selectedProductId),
    );

    return (
        <>
            <Head title="New SKU" />

            <PageHeader
                eyebrow="Catalog"
                title="New SKU"
                description="A sellable, stock-tracked unit for a product or variant."
            />

            <SectionCard>
                <Form {...SkuController.store.form()}>
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            <div className="row g-3">
                                <div className="col-md-6">
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
                                            value={selectedProductId}
                                            onChange={(event) =>
                                                setSelectedProductId(
                                                    event.target.value,
                                                )
                                            }
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
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="product_variant_id"
                                        label="Variant"
                                        error={errors.product_variant_id}
                                        hint="Leave unselected for a simple product."
                                    >
                                        <select
                                            id="product_variant_id"
                                            name="product_variant_id"
                                            className="form-select"
                                            defaultValue=""
                                        >
                                            <option value="">No variant</option>
                                            {variantsForProduct.map(
                                                (variant) => (
                                                    <option
                                                        key={variant.id}
                                                        value={variant.id}
                                                    >
                                                        {variant.name}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </FormField>
                                </div>
                            </div>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="sku"
                                        label="SKU code"
                                        error={errors.sku}
                                        required
                                    >
                                        <input
                                            id="sku"
                                            name="sku"
                                            type="text"
                                            className="form-control"
                                            placeholder="SK-2026-881"
                                            required
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="barcode"
                                        label="Barcode"
                                        error={errors.barcode}
                                    >
                                        <input
                                            id="barcode"
                                            name="barcode"
                                            type="text"
                                            className="form-control"
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="cost_price"
                                        label="Cost price"
                                        error={errors.cost_price}
                                        required
                                    >
                                        <input
                                            id="cost_price"
                                            name="cost_price"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            className="form-control"
                                            defaultValue={0}
                                            required
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="selling_price"
                                        label="Selling price"
                                        error={errors.selling_price}
                                        required
                                    >
                                        <input
                                            id="selling_price"
                                            name="selling_price"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            className="form-control"
                                            defaultValue={0}
                                            required
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="first_stock_date"
                                        label="First stock date"
                                        error={errors.first_stock_date}
                                    >
                                        <input
                                            id="first_stock_date"
                                            name="first_stock_date"
                                            type="date"
                                            className="form-control"
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="last_stock_date"
                                        label="Last stock date"
                                        error={errors.last_stock_date}
                                    >
                                        <input
                                            id="last_stock_date"
                                            name="last_stock_date"
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
                                    data-test="create-sku-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create SKU
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
