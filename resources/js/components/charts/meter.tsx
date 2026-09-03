import { cn } from '@/lib/utils';

type Props = {
    label: string;
    value: number;
    max?: number;
    /** Ratios at which the fill escalates to warning, then danger. */
    thresholds?: { warning: number; danger: number };
    valueLabel?: string;
    className?: string;
};

/**
 * Progress against a target. The fill carries severity; the track stays neutral
 * so the state reads across the whole bar.
 */
export default function Meter({
    label,
    value,
    max = 100,
    thresholds,
    valueLabel,
    className,
}: Props) {
    const ratio = max > 0 ? Math.min(Math.max(value / max, 0), 1) : 0;

    const tone = thresholds
        ? ratio >= thresholds.danger
            ? 'danger'
            : ratio >= thresholds.warning
              ? 'warning'
              : null
        : null;

    return (
        <div
            className={cn('app-meter', tone && `app-meter--${tone}`, className)}
        >
            <div className="app-meter__head">
                <span className="app-meter__label">{label}</span>
                <span className="app-meter__value">
                    {valueLabel ?? `${Math.round(ratio * 100)}%`}
                </span>
            </div>

            <div
                className="app-meter__track"
                role="progressbar"
                aria-label={label}
                aria-valuenow={value}
                aria-valuemin={0}
                aria-valuemax={max}
            >
                <div
                    className="app-meter__fill"
                    style={{ width: `${ratio * 100}%` }}
                />
            </div>
        </div>
    );
}
