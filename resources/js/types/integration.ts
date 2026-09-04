/**
 * The BuyAbans back-office integration — this application's only source of
 * catalog, stock and demand data.
 */

export type SyncStage =
    | 'all'
    | 'locations'
    | 'categories'
    | 'brands'
    | 'attributes'
    | 'products'
    | 'stock'
    | 'demand';

export type SyncStatus = 'running' | 'success' | 'failed';

/** The location grain demand is aggregated at. */
export type DemandGrain = 'warehouse' | 'channel' | 'national';

export type BuyabansSyncRun = {
    id: number;
    /**
     * Which stage of the sync this run covers. Named `stage` rather than
     * `resource` because Laravel's JsonResource exposes a `$resource` property
     * of its own that would shadow the column server-side.
     */
    stage: string;
    status: SyncStatus;
    records_fetched: number;
    records_written: number;
    pages: number;
    grain: DemandGrain | null;
    from_date: string | null;
    to_date: string | null;
    message: string | null;
    summary: Record<string, unknown> | null;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
};

/**
 * Rows of different grains describe the same underlying sales, so they are
 * reported separately and must never be summed together.
 */
export type DemandGrainSummary = {
    grain: DemandGrain;
    rows: number;
    skus: number;
    locations: number;
    first_date: string | null;
    last_date: string | null;
    units: number;
    unmatched_skus: number;
};

export type BuyabansSyncSummary = {
    by_grain: DemandGrainSummary[];
    catalog: {
        categories: number;
        brands: number;
        skus: number;
        warehouses: number;
        stock_levels: number;
    };
    /** False when API credentials are missing — a setup problem, not a failure. */
    configured: boolean;
    endpoint: string;
};
