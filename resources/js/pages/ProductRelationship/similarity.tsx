import { router } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { similarity } from '@/routes/product-relationship';
import type { SimilarityResult } from '@/types/advanced-intelligence';
import type { SkuOption } from '@/types/catalog';

type Props = {
    result: SimilarityResult | null;
    skuId: number | null;
    skuOptions: SkuOption[];
};

const TIER_LABELS: Record<NonNullable<SimilarityResult['tier']>, string> = {
    category_size: 'Same category and size',
    brand_category: 'Same brand and category',
    category: 'Same category',
};

export default function ProductRelationshipSimilarity({
    result,
    skuId,
    skuOptions,
}: Props) {
    const [selected, setSelected] = useState(skuId ? String(skuId) : '');

    function reload(nextSkuId: string) {
        setSelected(nextSkuId);
        router.get(similarity.url(), nextSkuId ? { sku_id: nextSkuId } : {}, {
            preserveState: true,
            replace: true,
        });
    }

    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title="Product similarity"
                description="For a given SKU, the closest peer group this application can determine automatically — same category and size first, falling back to same brand and category, then category alone. The same fallback hierarchy cold-start forecasting already uses internally."
            />

            <div className="app-card p-4">
                <label htmlFor="sku_id" className="form-label">
                    Choose a SKU
                </label>
                <select
                    id="sku_id"
                    className="form-select mb-4"
                    style={{ maxWidth: '24rem' }}
                    value={selected}
                    onChange={(event) => reload(event.target.value)}
                >
                    <option value="">Select a SKU…</option>
                    {skuOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.sku}
                        </option>
                    ))}
                </select>

                {!skuId && (
                    <p className="app-text-muted mb-0">
                        Choose a SKU above to see its most similar products.
                    </p>
                )}

                {skuId && result && result.tier === null && (
                    <EmptyState
                        title="No similar products found"
                        description="No other SKU shares this one's category, brand or size."
                    />
                )}

                {skuId && result && result.tier !== null && (
                    <div>
                        <StatusBadge
                            tone="info"
                            label={TIER_LABELS[result.tier]}
                        />
                        <ul className="list-unstyled mt-3 mb-0">
                            {result.skus.map((peer) => (
                                <li
                                    key={peer.id}
                                    className="d-flex justify-content-between border-bottom py-2"
                                >
                                    <span className="fw-semibold">
                                        {peer.sku}
                                    </span>
                                    <span className="app-text-muted">
                                        {peer.product_name}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    );
}
