export type ForecastRunStatus =
    'queued' | 'processing' | 'completed' | 'failed';

export type ForecastSource =
    | 'SKU_HISTORY'
    | 'CATEGORY_SIZE'
    | 'CATEGORY'
    | 'BRAND_CATEGORY'
    | 'HYBRID'
    | 'COLD_START';

export type ForecastRun = {
    id: number;
    warehouse_ids: number[] | null;
    horizon_days: number;
    status: ForecastRunStatus;
    status_label: string;
    model_version: string | null;
    forecasts_count?: number;
    started_at: string | null;
    finished_at: string | null;
    error_message: string | null;
    created_at: string | null;
};

export type Forecast = {
    id: number;
    forecast_run_id: number;
    warehouse?: { id: number; name: string } | null;
    sku?: { id: number; sku: string; product_name: string | null } | null;
    forecast_date: string;
    horizon_days: number;
    predicted_qty: number;
    lower_qty: number;
    upper_qty: number;
    confidence_score: number;
    forecast_source: ForecastSource;
    forecast_source_label: string;
    forecast_source_explanation: string;
    model_version: string | null;
    accuracy: {
        actual_qty: number;
        absolute_error: number;
        percentage_error: number | null;
    } | null;
};

/**
 * The plain-language summary above the forecast table — written for a reader
 * who has never seen this system.
 */
export type ForecastOverview = {
    summary: {
        products: number;
        forecasts: number;
        horizonDays: number;
        forecastDate: string | null;
        expectedUnits: number;
        rangeLow: number;
        rangeHigh: number;
        previousUnits: number;
        /** Null when there is no prior period to compare against. */
        changePercent: number | null;
        confidence: {
            score: number;
            label: string;
            tone: 'success' | 'warning' | 'danger';
            explanation: string;
        };
        basis: {
            label: string;
            explanation: string;
            count: number;
            share: number;
        }[];
    } | null;
    chart: {
        labels: string[];
        series: { name: string; values: (number | null)[] }[];
        band?: {
            lower: (number | null)[];
            upper: (number | null)[];
            seriesIndex: number;
        };
    };
    topProducts: { label: string; value: number }[];
};
