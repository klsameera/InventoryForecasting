import { Form, Head } from '@inertiajs/react';
import PromotionController from '@/actions/App/Http/Controllers/PromotionController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { SkuOption } from '@/types/catalog';

type Props = {
    skuOptions: SkuOption[];
};

export default function PromotionCreate({ skuOptions }: Props) {
    return (
        <>
            <Head title="New promotion" />

            <PageHeader
                eyebrow="Advanced intelligence"
                title="New promotion"
                description="Record a discount that ran (or will run) on a set of SKUs, so its real effect on demand can be measured afterward."
            />

            <SectionCard>
                <Form {...PromotionController.store.form()}>
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
                                    required
                                />
                            </FormField>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="discount_type"
                                        label="Discount type"
                                        error={errors.discount_type}
                                        required
                                    >
                                        <select
                                            id="discount_type"
                                            name="discount_type"
                                            className="form-select"
                                            defaultValue="PERCENTAGE"
                                            required
                                        >
                                            <option value="PERCENTAGE">
                                                Percentage
                                            </option>
                                            <option value="FIXED">
                                                Fixed amount
                                            </option>
                                        </select>
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="discount_value"
                                        label="Discount value"
                                        error={errors.discount_value}
                                        required
                                    >
                                        <input
                                            id="discount_value"
                                            name="discount_value"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            className="form-control"
                                            required
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="start_date"
                                        label="Start date"
                                        error={errors.start_date}
                                        required
                                    >
                                        <input
                                            id="start_date"
                                            name="start_date"
                                            type="date"
                                            className="form-control"
                                            required
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="end_date"
                                        label="End date"
                                        error={errors.end_date}
                                        required
                                    >
                                        <input
                                            id="end_date"
                                            name="end_date"
                                            type="date"
                                            className="form-control"
                                            required
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <FormField
                                id="sku_ids"
                                label="SKUs"
                                error={errors.sku_ids}
                                hint="Hold Ctrl/Cmd to select multiple"
                            >
                                <select
                                    id="sku_ids"
                                    name="sku_ids[]"
                                    className="form-select"
                                    multiple
                                    size={8}
                                >
                                    {skuOptions.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.sku}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <FormField
                                id="notes"
                                label="Notes"
                                error={errors.notes}
                                hint="Optional"
                            >
                                <textarea
                                    id="notes"
                                    name="notes"
                                    className="form-control"
                                    rows={2}
                                />
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create promotion
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
