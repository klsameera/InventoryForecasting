export type LegendItem = {
    label: string;
    color: string;
};

type Props = {
    items: LegendItem[];
    /** Line charts read better with a line key than a square swatch. */
    variant?: 'swatch' | 'key';
};

/**
 * Identity is never carried by colour alone — every chart with two or more
 * series renders this.
 */
export default function ChartLegend({ items, variant = 'swatch' }: Props) {
    if (items.length < 2) {
        return null;
    }

    return (
        <ul className="app-chart-legend">
            {items.map((item) => (
                <li key={item.label} className="app-chart-legend__item">
                    <span
                        className={
                            variant === 'key'
                                ? 'app-chart-legend__key'
                                : 'app-chart-legend__swatch'
                        }
                        style={{ color: item.color }}
                        aria-hidden="true"
                    />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}
