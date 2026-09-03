type Props = {
    values: number[];
    label: string;
    color?: string;
};

const WIDTH = 200;
const HEIGHT = 40;
const PADDING = 4;

/**
 * Micro trend for stat tiles: no axes, no chrome, no tooltip — the tile's value
 * is the label. The final point is accented so "where it ended" reads at a glance.
 */
export default function Sparkline({
    values,
    label,
    color = 'var(--app-chart-1)',
}: Props) {
    if (values.length < 2) {
        return null;
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const stepX = (WIDTH - PADDING * 2) / (values.length - 1);

    const points = values.map((value, index) => ({
        x: PADDING + index * stepX,
        y: HEIGHT - PADDING - ((value - min) / span) * (HEIGHT - PADDING * 2),
    }));

    const line = points
        .map(
            (point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`,
        )
        .join(' ');

    const area = `${line} L${points[points.length - 1].x},${HEIGHT} L${points[0].x},${HEIGHT} Z`;
    const last = points[points.length - 1];

    return (
        <svg
            className="app-sparkline"
            viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
            preserveAspectRatio="none"
            role="img"
            aria-label={label}
        >
            <path className="app-chart__area" d={area} fill={color} />
            <path
                className="app-chart__line"
                d={line}
                stroke={color}
                vectorEffect="non-scaling-stroke"
            />
            <circle
                className="app-chart__marker"
                cx={last.x}
                cy={last.y}
                r={3.5}
                fill={color}
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
