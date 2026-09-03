/**
 * Fixed categorical order, defined once in `resources/scss/base/_tokens.scss`
 * and validated for colour-vision separation against both surfaces.
 *
 * Assign by slot index and never by rank, so filtering a series out does not
 * repaint the ones that remain. There is no slot 7: fold the tail into "Other"
 * or split the chart into small multiples instead.
 */
export const CHART_SLOTS = [
    'var(--app-chart-1)',
    'var(--app-chart-2)',
    'var(--app-chart-3)',
    'var(--app-chart-4)',
    'var(--app-chart-5)',
    'var(--app-chart-6)',
] as const;

export const MAX_SERIES = CHART_SLOTS.length;

export function slotColor(index: number): string {
    return CHART_SLOTS[index % MAX_SERIES];
}

/**
 * Nice round axis bounds, so ticks land on readable numbers.
 */
export function niceScale(
    max: number,
    tickCount = 4,
): { max: number; ticks: number[] } {
    if (max <= 0) {
        return { max: 1, ticks: [0, 1] };
    }

    const rawStep = max / tickCount;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const normalised = rawStep / magnitude;
    const stepMultiple = [1, 2, 2.5, 5, 10].find((m) => normalised <= m) ?? 10;
    const step = stepMultiple * magnitude;
    const niceMax = Math.ceil(max / step) * step;

    const ticks: number[] = [];

    for (let value = 0; value <= niceMax + step / 2; value += step) {
        ticks.push(Number(value.toFixed(10)));
    }

    return { max: niceMax, ticks };
}

export function formatTick(value: number): string {
    if (Math.abs(value) >= 1000) {
        return new Intl.NumberFormat(undefined, {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(value);
    }

    return value.toLocaleString();
}
