import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import SkuController from '@/actions/App/Http/Controllers/SkuController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option, Sku, VariantOption } from '@/types/catalog';

type Props = {
    id: number;
    sku: Sku;
    productOptions: Option[];
    variantOptions: VariantOption[];
};

export default function SkuEdit({
    id,
    sku,
    productOptions,
    variantOptions,
}: Props) {
    const [selectedProductId, setSelectedProductId] = useState(sku.product_id);

    const variantsForProduct = variantOptions.filter(
        (variant) => variant.product_id === Number(selectedProductId),
    );

    return (
        <>
            <Head title={`Edit ${sku.sku}`} />

            <PageHeader
                eyebrow="Catalog"
                title={`Edit ${sku.sku}`}
                description="Update this SKU's details."
            />

            <SectionCard>
                <Form
                    {...SkuController.update.form(id)}
                    options={{ preserveScroll: true }}
                >
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
                                                    Number(event.target.value),
                                                )
                                            }
                                            required
                                        >
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
                                            defaultValue={
                                                sku.product_variant_id ?? ''
                                            }
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
                                            defaultValue={sku.sku}
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
                                            defaultValue={sku.barcode ?? ''}
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
                                            defaultValue={sku.cost_price}
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
                                            defaultValue={sku.selling_price}
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
                                            defaultValue={
                                                sku.first_stock_date ?? ''
                                            }
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
                                            defaultValue={
                                                sku.last_stock_date ?? ''
                                            }
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
                                        defaultChecked={sku.status}
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
                                    data-test="update-sku-button"
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
