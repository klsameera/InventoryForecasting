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
    model_version: string | null;
    accuracy: {
        actual_qty: number;
        absolute_error: number;
        percentage_error: number | null;
    } | null;
};
