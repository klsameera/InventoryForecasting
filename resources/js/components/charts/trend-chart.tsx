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
    /**
     * `null` is a gap, not a zero. It is what lets one chart hold what actually
     * happened and what is expected next on a single axis, without either
     * series claiming values for the other's stretch of time.
     */
    values: (number | null)[];
};

/**
 * A range drawn behind a series — a forecast's plausible span.
 *
 * Aligned index-for-index with `labels`, so it can start partway along and read
 * as an extension of the line rather than a second chart.
 */
export type TrendBand = {
    lower: (number | null)[];
    upper: (number | null)[];
    /** Which series' colour the band borrows. */
    seriesIndex: number;
};

type Props = {
    labels: string[];
    series: TrendSeries[];
    band?: TrendBand;
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
    band,
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
        const values = visibleSeries.flatMap((item) => item.values);
        const bandTop = band?.upper ?? [];
        const max = Math.max(
            0,
            ...[...values, ...bandTop].filter(
                (value): value is number => value !== null,
            ),
        );

        return niceScale(max);
    }, [visibleSeries, band]);

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
    const baseline = PADDING.top + plotHeight;

    const xAt = (index: number) => PADDING.left + index * stepX;
    const yAt = (value: number) =>
        PADDING.top + plotHeight - (value / scale.max) * plotHeight;

    /**
     * Contiguous runs of real values. A gap ends a run rather than being drawn
     * through, so the line never invents a value it was not given.
     */
    const runsOf = (values: (number | null)[]) => {
        const runs: { index: number; value: number }[][] = [];
        let current: { index: number; value: number }[] = [];

        values.forEach((value, index) => {
            if (value === null) {
                if (current.length > 0) {
                    runs.push(current);
                    current = [];
                }

                return;
            }

            current.push({ index, value });
        });

        if (current.length > 0) {
            runs.push(current);
        }

        return runs;
    };

    const pathFor = (run: { index: number; value: number }[]) =>
        run
            .map(
                (point, position) =>
                    `${position === 0 ? 'M' : 'L'}${xAt(point.index)},${yAt(point.value)}`,
            )
            .join(' ');

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
    const single = visibleSeries.length === 1 && !band;
    const activeX = activeIndex === null ? 0 : xAt(activeIndex);

    const bandPath = (() => {
        if (!band) {
            return null;
        }

        const points = band.upper
            .map((upper, index) => ({ index, upper, lower: band.lower[index] }))
            .filter(
                (
                    point,
                ): point is { index: number; upper: number; lower: number } =>
                    point.upper !== null && point.lower !== null,
            );

        if (points.length < 2) {
            return null;
        }

        const top = points
            .map(
                (point, position) =>
                    `${position === 0 ? 'M' : 'L'}${xAt(point.index)},${yAt(point.upper)}`,
            )
            .join(' ');

        const bottom = [...points]
            .reverse()
            .map((point) => `L${xAt(point.index)},${yAt(point.lower)}`)
            .join(' ');

        return `${top} ${bottom} Z`;
    })();

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
                    {scale.ticks.map((tick) => (
                        <line
                            key={tick}
                            x1={PADDING.left}
                            x2={VIEW_WIDTH - PADDING.right}
                            y1={yAt(tick)}
                            y2={yAt(tick)}
                        />
                    ))}
                </g>

                {scale.ticks.map((tick) => (
                    <text
                        key={tick}
                        className="app-chart__axis-label"
                        x={PADDING.left - 8}
                        y={yAt(tick) + 3.5}
                        textAnchor="end"
                    >
                        {formatTick(tick)}
                    </text>
                ))}

                {labels.map((label, index) =>
                    index % labelStride === 0 ? (
                        <text
                            key={label + index}
                            className="app-chart__axis-label"
                            x={xAt(index)}
                            y={height - 8}
                            textAnchor="middle"
                        >
                            {label}
                        </text>
                    ) : null,
                )}

                {/* Behind the lines: the range is context, not a mark of its own. */}
                {bandPath !== null && band && (
                    <path
                        className="app-chart__band"
                        d={bandPath}
                        fill={slotColor(band.seriesIndex)}
                    />
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
                    const runs = runsOf(item.values);

                    if (runs.length === 0) {
                        return null;
                    }

                    const lastRun = runs[runs.length - 1];
                    const last = lastRun[lastRun.length - 1];
                    const activeValue =
                        activeIndex === null ? null : item.values[activeIndex];

                    return (
                        <g key={item.name}>
                            {single &&
                                runs.map((run) => (
                                    <path
                                        key={`area-${run[0].index}`}
                                        className="app-chart__area"
                                        d={`${pathFor(run)} L${xAt(run[run.length - 1].index)},${baseline} L${xAt(run[0].index)},${baseline} Z`}
                                        fill={color}
                                    />
                                ))}

                            {runs.map((run) => (
                                <path
                                    key={`line-${run[0].index}`}
                                    className="app-chart__line"
                                    d={pathFor(run)}
                                    stroke={color}
                                />
                            ))}

                            <circle
                                className="app-chart__marker"
                                cx={xAt(last.index)}
                                cy={yAt(last.value)}
                                r={4}
                                fill={color}
                            />
                            {/* Direct label rides the end of the line — the
                                axis and tooltip carry every other value. */}
                            <text
                                className="app-chart__value-label"
                                x={xAt(last.index) + 10}
                                y={yAt(last.value) + 4}
                            >
                                {valueFormatter(last.value)}
                            </text>

                            {activeIndex !== null && activeValue !== null && (
                                <circle
                                    className="app-chart__marker"
                                    cx={xAt(activeIndex)}
                                    cy={yAt(activeValue)}
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
                    {visibleSeries.map((item, seriesIndex) => {
                        const value = item.values[activeIndex];

                        if (value === null) {
                            return null;
                        }

                        return (
                            <div
                                key={item.name}
                                className="app-chart-tooltip__row"
                            >
                                <span
                                    className="app-chart-tooltip__swatch"
                                    style={{ color: slotColor(seriesIndex) }}
                                    aria-hidden="true"
                                />
                                {item.name}
                                <span className="app-chart-tooltip__value">
                                    {valueFormatter(value)}
                                </span>
                            </div>
                        );
                    })}
                    {band &&
                        band.lower[activeIndex] !== null &&
                        band.upper[activeIndex] !== null && (
                            <div className="app-chart-tooltip__row">
                                <span
                                    className="app-chart-tooltip__swatch app-chart-tooltip__swatch--band"
                                    style={{
                                        color: slotColor(band.seriesIndex),
                                    }}
                                    aria-hidden="true"
                                />
                                Range
                                <span className="app-chart-tooltip__value">
                                    {valueFormatter(
                                        band.lower[activeIndex] as number,
                                    )}
                                    {' – '}
                                    {valueFormatter(
                                        band.upper[activeIndex] as number,
                                    )}
                                </span>
                            </div>
                        )}
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
