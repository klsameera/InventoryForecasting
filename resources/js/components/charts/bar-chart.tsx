import { useMemo, useState } from 'react';
import {
    formatTick,
    niceScale,
    slotColor,
} from '@/components/charts/chart-colors';
import EmptyState from '@/components/empty-state';

export type BarDatum = {
    label: string;
    value: number;
    /**
     * Which categorical slot to draw this bar in. Omit for a plain ranked
     * chart, where every bar shares slot 0.
     *
     * Only for bars that are **states** rather than ranks — cover bands, say,
     * where "out of stock" and "over six months" mean different things and the
     * reader should not have to re-learn which is which each visit. The slot is
     * fixed per state by the caller, never derived from the value, so a bar
     * that shrinks to nothing does not repaint its neighbours.
     */
    colorSlot?: number;
};

type Props = {
    items: BarDatum[];
    height?: number;
    /**
     * Bars run left-to-right with the category name beside each one.
     *
     * The right form whenever the labels are words rather than dates or short
     * codes: eight product names under vertical columns collide into an
     * unreadable smear, and rotating them only trades one problem for another.
     */
    horizontal?: boolean;
    valueFormatter?: (value: number) => string;
    emptyTitle?: string;
    emptyDescription?: string;
};

const VIEW_WIDTH = 760;
const PADDING = { top: 24, right: 12, bottom: 32, left: 44 };
const MAX_BAR_WIDTH = 24;
const BAR_GAP = 2;
const CORNER_RADIUS = 4;

/**
 * Column path with a 4px rounded data-end and square corners at the baseline.
 */
function columnPath(x: number, y: number, width: number, height: number) {
    const radius = Math.min(CORNER_RADIUS, width / 2, height);
    const bottom = y + height;

    return [
        `M${x},${bottom}`,
        `L${x},${y + radius}`,
        `Q${x},${y} ${x + radius},${y}`,
        `L${x + width - radius},${y}`,
        `Q${x + width},${y} ${x + width},${y + radius}`,
        `L${x + width},${bottom}`,
        'Z',
    ].join(' ');
}

/**
 * The same bar lying down: rounded at the value end, square against the axis.
 */
function rowPath(x: number, y: number, width: number, height: number) {
    const radius = Math.min(CORNER_RADIUS, height / 2, width);
    const right = x + width;

    return [
        `M${x},${y}`,
        `L${right - radius},${y}`,
        `Q${right},${y} ${right},${y + radius}`,
        `L${right},${y + height - radius}`,
        `Q${right},${y + height} ${right - radius},${y + height}`,
        `L${x},${y + height}`,
        'Z',
    ].join(' ');
}

const HORIZONTAL_PADDING = { top: 8, right: 56, bottom: 24, left: 168 };
const MAX_BAR_THICKNESS = 18;

/**
 * Magnitude across nominal categories. One colour for every bar unless the
 * caller assigns slots per state — darkening by value would double-encode the
 * length, which the bar already carries.
 */
export default function BarChart({
    items,
    height = 260,
    horizontal = false,
    valueFormatter = formatTick,
    emptyTitle = 'No data to compare yet',
    emptyDescription,
}: Props) {
    const [activeIndex, setActiveIndex] = useState<number | null>(null);

    const scale = useMemo(
        () => niceScale(Math.max(0, ...items.map((item) => item.value))),
        [items],
    );

    if (items.length === 0) {
        return (
            <EmptyState
                compact
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }

    if (horizontal) {
        const plotWidth =
            VIEW_WIDTH - HORIZONTAL_PADDING.left - HORIZONTAL_PADDING.right;
        const plotHeight =
            height - HORIZONTAL_PADDING.top - HORIZONTAL_PADDING.bottom;
        const rowBand = plotHeight / items.length;
        const thickness = Math.min(
            MAX_BAR_THICKNESS,
            Math.max(4, rowBand - BAR_GAP * 2),
        );

        return (
            <div className="app-chart">
                <svg
                    className={`app-chart__svg${activeIndex !== null ? ' app-chart--dimmed' : ''}`}
                    viewBox={`0 0 ${VIEW_WIDTH} ${height}`}
                    height={height}
                    role="img"
                    aria-label="Comparison by category"
                    onPointerLeave={() => setActiveIndex(null)}
                >
                    {items.map((item, index) => {
                        const barWidth = Math.max(
                            1,
                            (item.value / scale.max) * plotWidth,
                        );
                        const y =
                            HORIZONTAL_PADDING.top +
                            rowBand * index +
                            (rowBand - thickness) / 2;

                        return (
                            <g
                                key={item.label}
                                onPointerEnter={() => setActiveIndex(index)}
                            >
                                <rect
                                    x={0}
                                    y={HORIZONTAL_PADDING.top + rowBand * index}
                                    width={VIEW_WIDTH}
                                    height={rowBand}
                                    fill="transparent"
                                />
                                <text
                                    className="app-chart__axis-label"
                                    x={HORIZONTAL_PADDING.left - 10}
                                    y={y + thickness / 2 + 3.5}
                                    textAnchor="end"
                                >
                                    {item.label}
                                </text>
                                <path
                                    className={`app-chart__bar${activeIndex === index ? ' is-hovered' : ''}`}
                                    d={rowPath(
                                        HORIZONTAL_PADDING.left,
                                        y,
                                        barWidth,
                                        thickness,
                                    )}
                                    fill={slotColor(item.colorSlot ?? 0)}
                                />
                                {/* Every bar carries its value: with the
                                    categories named down the side there is no
                                    axis to read a length against. */}
                                <text
                                    className="app-chart__value-label"
                                    x={HORIZONTAL_PADDING.left + barWidth + 8}
                                    y={y + thickness / 2 + 3.5}
                                >
                                    {valueFormatter(item.value)}
                                </text>
                            </g>
                        );
                    })}
                </svg>
            </div>
        );
    }

    const plotWidth = VIEW_WIDTH - PADDING.left - PADDING.right;
    const plotHeight = height - PADDING.top - PADDING.bottom;
    const band = plotWidth / items.length;
    const barWidth = Math.min(MAX_BAR_WIDTH, Math.max(4, band - BAR_GAP * 2));
    const baseline = PADDING.top + plotHeight;
    const color = slotColor(0);

    const peakIndex = items.reduce(
        (best, item, index) => (item.value > items[best].value ? index : best),
        0,
    );

    return (
        <div className="app-chart">
            <svg
                className={`app-chart__svg${activeIndex !== null ? ' app-chart--dimmed' : ''}`}
                viewBox={`0 0 ${VIEW_WIDTH} ${height}`}
                height={height}
                role="img"
                aria-label="Comparison by category"
                onPointerLeave={() => setActiveIndex(null)}
            >
                <g className="app-chart__grid">
                    {scale.ticks.map((tick) => {
                        const y = baseline - (tick / scale.max) * plotHeight;

                        return (
                            <line
                                key={tick}
                                x1={PADDING.left}
                                x2={VIEW_WIDTH - PADDING.right}
                                y1={y}
                                y2={y}
                            />
                        );
                    })}
                </g>

                {scale.ticks.map((tick) => (
                    <text
                        key={tick}
                        className="app-chart__axis-label"
                        x={PADDING.left - 8}
                        y={baseline - (tick / scale.max) * plotHeight + 3.5}
                        textAnchor="end"
                    >
                        {formatTick(tick)}
                    </text>
                ))}

                {items.map((item, index) => {
                    const barHeight = Math.max(
                        1,
                        (item.value / scale.max) * plotHeight,
                    );
                    const x =
                        PADDING.left + band * index + (band - barWidth) / 2;
                    const y = baseline - barHeight;

                    return (
                        <g
                            key={item.label}
                            onPointerEnter={() => setActiveIndex(index)}
                        >
                            {/* Full-band hit target so hover does not demand
                                pixel-accurate aim at a thin bar. */}
                            <rect
                                x={PADDING.left + band * index}
                                y={PADDING.top}
                                width={band}
                                height={plotHeight}
                                fill="transparent"
                            />
                            <path
                                className={`app-chart__bar${activeIndex === index ? ' is-hovered' : ''}`}
                                d={columnPath(x, y, barWidth, barHeight)}
                                fill={color}
                            />
                            {index === peakIndex && (
                                <text
                                    className="app-chart__value-label"
                                    x={x + barWidth / 2}
                                    y={y - 7}
                                    textAnchor="middle"
                                >
                                    {valueFormatter(item.value)}
                                </text>
                            )}
                            <text
                                className="app-chart__axis-label"
                                x={PADDING.left + band * index + band / 2}
                                y={height - 10}
                                textAnchor="middle"
                            >
                                {item.label}
                            </text>
                        </g>
                    );
                })}
            </svg>

            {activeIndex !== null && (
                <div
                    className="app-chart-tooltip"
                    style={{
                        left: `${((PADDING.left + band * activeIndex + band / 2) / VIEW_WIDTH) * 100}%`,
                        top: `${(PADDING.top / height) * 100}%`,
                    }}
                >
                    <p className="app-chart-tooltip__title mb-0">
                        {items[activeIndex].label}
                    </p>
                    <div className="app-chart-tooltip__row">
                        <span
                            className="app-chart-tooltip__swatch"
                            style={{ color }}
                            aria-hidden="true"
                        />
                        Total
                        <span className="app-chart-tooltip__value">
                            {valueFormatter(items[activeIndex].value)}
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
