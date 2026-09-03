import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import PromotionController from '@/actions/App/Http/Controllers/PromotionController';
import ConfirmDialog from '@/components/confirm-dialog';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import { index, impact } from '@/routes/promotion';
import type { Promotion } from '@/types/advanced-intelligence';
import type { SkuOption } from '@/types/catalog';
import type { StatusTone } from '@/types/ui';

type Props = {
    id: number;
    promotion: Promotion;
    skuOptions: SkuOption[];
};

const STATE_TONES: Record<Promotion['state'], StatusTone> = {
    upcoming: 'info',
    active: 'success',
    ended: 'secondary',
};

export default function PromotionEdit({ id, promotion, skuOptions }: Props) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const selectedSkuIds = (promotion.skus ?? []).map((sku) => String(sku.id));

    return (
        <>
            <Head title={promotion.name} />

            <PageHeader
                eyebrow="Advanced intelligence"
                title={promotion.name}
                description={`${promotion.start_date} – ${promotion.end_date}`}
                actions={
                    <div className="d-flex align-items-center gap-2">
                        <StatusBadge
                            tone={STATE_TONES[promotion.state]}
                            label={
                                promotion.state.charAt(0).toUpperCase() +
                                promotion.state.slice(1)
                            }
                        />
                        {promotion.state === 'ended' && (
                            <Link
                                href={impact.url(id)}
                                className="btn btn-surface"
                            >
                                View impact
                            </Link>
                        )}
                    </div>
                }
            />

            <SectionCard>
                <Form
                    {...PromotionController.update.form(id)}
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
                                    defaultValue={promotion.name}
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
                                            defaultValue={
                                                promotion.discount_type
                                            }
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
                                            defaultValue={
                                                promotion.discount_value
                                            }
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
                                            defaultValue={promotion.start_date}
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
                                            defaultValue={promotion.end_date}
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
                                    defaultValue={selectedSkuIds}
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
                                    defaultValue={promotion.notes ?? ''}
                                />
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                >
                                    {processing && <Spinner size="sm" />}
                                    Save changes
                                </button>

                                <button
                                    type="button"
                                    className="btn btn-quiet-danger"
                                    onClick={() => setConfirmingDelete(true)}
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>

            <ConfirmDialog
                open={confirmingDelete}
                onCancel={() => setConfirmingDelete(false)}
                onConfirm={() => {
                    router.delete(PromotionController.delete.url(id), {
                        onSuccess: () => router.visit(index.url()),
                    });
                }}
                title="Delete promotion"
                description={`This permanently removes ${promotion.name}.`}
                confirmLabel="Delete"
            />
        </>
    );
}
