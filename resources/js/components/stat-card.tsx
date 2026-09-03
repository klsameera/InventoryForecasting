import { Minus, TrendingDown, TrendingUp } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import Sparkline from '@/components/charts/sparkline';
import { cn } from '@/lib/utils';

type Tone = 'brand' | 'success' | 'warning' | 'danger' | 'info';

type Props = {
    label: string;
    value: ReactNode;
    icon?: ComponentType<{ 'aria-hidden'?: boolean | 'true' | 'false' }>;
    tone?: Tone;
    /** Signed change against `deltaLabel`'s period, e.g. 4.2 for +4.2%. */
    delta?: number | null;
    deltaLabel?: string;
    /** Whether a rising number is a good outcome. Flip for cost-style metrics. */
    upIsGood?: boolean;
    /** Recent history for the micro trend. Omitted renders no sparkline. */
    trend?: number[];
    className?: string;
};

function TrendPill({ delta, upIsGood }: { delta: number; upIsGood: boolean }) {
    if (delta === 0) {
        return (
            <span className="app-trend app-trend--flat">
                <Minus aria-hidden="true" />
                0%
            </span>
        );
    }

    const rising = delta > 0;
    const good = rising === upIsGood;
    const Icon = rising ? TrendingUp : TrendingDown;

    return (
        <span
            className={cn(
                'app-trend',
                good ? 'app-trend--up' : 'app-trend--down',
            )}
        >
            <Icon aria-hidden="true" />
            {rising ? '+' : ''}
            {delta}%
        </span>
    );
}

export default function StatCard({
    label,
    value,
    icon: Icon,
    tone = 'brand',
    delta = null,
    deltaLabel,
    upIsGood = true,
    trend,
    className,
}: Props) {
    return (
        <article className={cn('app-stat', className)}>
            <div className="app-stat__top">
                <p className="app-stat__label">{label}</p>

                {Icon && (
                    <span
                        className={cn(
                            'app-stat__icon',
                            tone !== 'brand' && `app-stat__icon--${tone}`,
                        )}
                    >
                        <Icon aria-hidden="true" />
                    </span>
                )}
            </div>

            <p className="app-stat__value mb-0">{value}</p>

            {(delta !== null || deltaLabel) && (
                <div className="app-stat__meta">
                    {delta !== null && (
                        <TrendPill delta={delta} upIsGood={upIsGood} />
                    )}
                    {deltaLabel && <span>{deltaLabel}</span>}
                </div>
            )}

            {trend && trend.length > 1 && (
                <Sparkline values={trend} label={`${label} trend`} />
            )}
        </article>
    );
}
