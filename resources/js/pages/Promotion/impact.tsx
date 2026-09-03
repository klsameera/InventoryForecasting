import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { index } from '@/routes/promotion';
import type { Promotion, PromotionImpact } from '@/types/advanced-intelligence';
import type { StatusTone } from '@/types/ui';

type Props = {
    promotion: Promotion;
    impact: PromotionImpact;
};

function changeTone(percentChange: number | null): StatusTone {
    if (percentChange === null) {
        return 'secondary';
    }

    return percentChange > 0 ? 'success' : 'danger';
}

export default function PromotionImpact({ promotion, impact }: Props) {
    return (
        <>
            <PageHeader
                eyebrow="Advanced intelligence"
                title={`Impact: ${promotion.name}`}
                description={`${promotion.start_date} – ${promotion.end_date}, compared against an equal-length window immediately before it started.`}
                actions={
                    <Link href={index.url()} className="btn btn-surface">
                        <ArrowLeft aria-hidden="true" />
                        Back to promotions
                    </Link>
                }
            />

            {!impact.elapsed && (
                <EmptyState
                    title="This promotion hasn't ended yet"
                    description="Impact can only be measured once the promotion's window has actually elapsed."
                />
            )}

            {impact.elapsed && impact.skus.length === 0 && (
                <EmptyState
                    title="No SKUs to measure"
                    description="This promotion has no SKUs assigned to it."
                />
            )}

            {impact.elapsed && impact.skus.length > 0 && (
                <div className="app-card p-0">
                    <div className="table-responsive">
                        <table className="table app-table mb-0">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th className="text-end">
                                        Baseline daily rate
                                    </th>
                                    <th className="text-end">
                                        During daily rate
                                    </th>
                                    <th className="text-end">Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                {impact.skus.map((row) => (
                                    <tr key={row.sku_id}>
                                        <td>
                                            <p className="fw-semibold mb-0">
                                                {row.sku}
                                            </p>
                                            <p className="app-text-muted small mb-0">
                                                {row.product_name ?? '—'}
                                            </p>
                                        </td>
                                        <td className="text-end">
                                            {row.baseline_daily_rate.toFixed(2)}
                                        </td>
                                        <td className="text-end">
                                            {row.during_daily_rate.toFixed(2)}
                                        </td>
                                        <td className="text-end">
                                            {row.percent_change !== null ? (
                                                <StatusBadge
                                                    tone={changeTone(
                                                        row.percent_change,
                                                    )}
                                                    label={`${row.percent_change > 0 ? '+' : ''}${row.percent_change.toFixed(1)}%`}
                                                />
                                            ) : (
                                                <span className="app-text-muted">
                                                    No baseline demand
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </>
    );
}
