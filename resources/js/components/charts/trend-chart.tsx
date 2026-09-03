import { useMemo, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import {
    formatTick,
    MAX_SERIES,
    niceScale,
    slotColor,
} from '@/components/charts/chart-colors';
import ChartLegend from '@/components/charts/chart-legend';
import EmptyState from '@/components/empty-state';

export type TrendSeries = {
    name: string;
    values: number[];
};

type Props = {
    labels: string[];
    series: TrendSeries[];
    height?: number;
    valueFormatter?: (value: number) => string;
    emptyTitle?: string;
    emptyDescription?: string;
};

const VIEW_WIDTH = 760;
const PADDING = { top: 16, right: 56, bottom: 28, left: 44 };

/**
 * Change-over-time on a single axis. Two measures of different scale go in two
 * charts — never a second y-axis.
 */
export default function TrendChart({
    labels,
    series,
    height = 260,
    valueFormatter = formatTick,
    emptyTitle = 'No trend data yet',
    emptyDescription,
}: Props) {
    const svgRef = useRef<SVGSVGElement>(null);
    const [activeIndex, setActiveIndex] = useState<number | null>(null);

    const visibleSeries = series.slice(0, MAX_SERIES);
    const hasData = labels.length > 1 && visibleSeries.length > 0;

    const scale = useMemo(() => {
        const max = Math.max(
            0,
            ...visibleSeries.flatMap((item) => item.values),
        );

        return niceScale(max);
    }, [visibleSeries]);

    if (!hasData) {
        return (
            <EmptyState
                compact
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }

    const plotWidth = VIEW_WIDTH - PADDING.left - PADDING.right;
    const plotHeight = height - PADDING.top - PADDING.bottom;
    const stepX = plotWidth / (labels.length - 1);

    const pointAt = (value: number, index: number) => ({
        x: PADDING.left + index * stepX,
        y: PADDING.top + plotHeight - (value / scale.max) * plotHeight,
    });

    const handlePointerMove = (
        event: ReactPointerEvent<SVGSVGElement>,
    ): void => {
        const rect = svgRef.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        const ratio = VIEW_WIDTH / rect.width;
        const x = (event.clientX - rect.left) * ratio - PADDING.left;
        const index = Math.round(x / stepX);

        setActiveIndex(Math.min(Math.max(index, 0), labels.length - 1));
    };

    // Label every tick when they fit; otherwise thin them out evenly.
    const labelStride = Math.ceil(labels.length / 8);
    const single = visibleSeries.length === 1;
    const activeX =
        activeIndex === null ? 0 : PADDING.left + activeIndex * stepX;

    return (
        <div className="app-chart">
            <svg
                ref={svgRef}
                className="app-chart__svg"
                viewBox={`0 0 ${VIEW_WIDTH} ${height}`}
                height={height}
                role="img"
                aria-label={`Trend of ${visibleSeries.map((item) => item.name).join(', ')}`}
                onPointerMove={handlePointerMove}
                onPointerLeave={() => setActiveIndex(null)}
            >
                <g className="app-chart__grid">
                    {scale.ticks.map((tick) => {
                        const y =
                            PADDING.top +
                            plotHeight -
                            (tick / scale.max) * plotHeight;

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

                {scale.ticks.map((tick) => {
                    const y =
                        PADDING.top +
                        plotHeight -
                        (tick / scale.max) * plotHeight;

                    return (
                        <text
                            key={tick}
                            className="app-chart__axis-label"
                            x={PADDING.left - 8}
                            y={y + 3.5}
                            textAnchor="end"
                        >
                            {formatTick(tick)}
                        </text>
                    );
                })}

                {labels.map((label, index) =>
                    index % labelStride === 0 ? (
                        <text
                            key={label + index}
                            className="app-chart__axis-label"
                            x={PADDING.left + index * stepX}
                            y={height - 8}
                            textAnchor="middle"
                        >
                            {label}
                        </text>
                    ) : null,
                )}

                {activeIndex !== null && (
                    <line
                        className="app-chart__crosshair"
                        x1={activeX}
                        x2={activeX}
                        y1={PADDING.top}
                        y2={PADDING.top + plotHeight}
                    />
                )}

                {visibleSeries.map((item, seriesIndex) => {
                    const color = slotColor(seriesIndex);
                    const points = item.values.map(pointAt);
                    const line = points
                        .map(
                            (point, index) =>
                                `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`,
                        )
                        .join(' ');
                    const baseline = PADDING.top + plotHeight;
                    const area = `${line} L${points[points.length - 1].x},${baseline} L${points[0].x},${baseline} Z`;
                    const last = points[points.length - 1];

                    return (
                        <g key={item.name}>
                            {single && (
                                <path
                                    className="app-chart__area"
                                    d={area}
                                    fill={color}
                                />
                            )}
                            <path
                                className="app-chart__line"
                                d={line}
                                stroke={color}
                            />
                            <circle
                                className="app-chart__marker"
                                cx={last.x}
                                cy={last.y}
                                r={4}
                                fill={color}
                            />
                            {/* Direct label rides the end of the line — the
                                axis and tooltip carry every other value. */}
                            <text
                                className="app-chart__value-label"
                                x={last.x + 10}
                                y={last.y + 4}
                            >
                                {valueFormatter(
                                    item.values[item.values.length - 1],
                                )}
                            </text>

                            {activeIndex !== null && (
                                <circle
                                    className="app-chart__marker"
                                    cx={points[activeIndex].x}
                                    cy={points[activeIndex].y}
                                    r={4.5}
                                    fill={color}
                                />
                            )}
                        </g>
                    );
                })}
            </svg>

            {activeIndex !== null && (
                <div
                    className="app-chart-tooltip"
                    style={{
                        left: `${(activeX / VIEW_WIDTH) * 100}%`,
                        top: `${(PADDING.top / height) * 100}%`,
                    }}
                >
                    <p className="app-chart-tooltip__title mb-0">
                        {labels[activeIndex]}
                    </p>
                    {visibleSeries.map((item, seriesIndex) => (
                        <div key={item.name} className="app-chart-tooltip__row">
                            <span
                                className="app-chart-tooltip__swatch"
                                style={{ color: slotColor(seriesIndex) }}
                                aria-hidden="true"
                            />
                            {item.name}
                            <span className="app-chart-tooltip__value">
                                {valueFormatter(item.values[activeIndex])}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            <ChartLegend
                variant="key"
                items={visibleSeries.map((item, index) => ({
                    label: item.name,
                    color: slotColor(index),
                }))}
            />
        </div>
    );
}
