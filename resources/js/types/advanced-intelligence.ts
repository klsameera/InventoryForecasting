export type LostSalesRow = {
    warehouse_id: number;
    warehouse_name: string;
    sku_id: number;
    sku: string;
    product_name: string;
    stockout_days: number;
    normal_daily_rate: number;
    estimated_lost_units: number;
};

export type DemandAnomalyRow = {
    warehouse_id: number;
    warehouse_name: string;
    sku_id: number;
    sku: string;
    product_name: string;
    snapshot_date: string;
    actual_qty: number;
    expected_qty: number;
    z_score: number;
};

export type SimilarSku = {
    id: number;
    sku: string;
    product_name: string;
};

export type SimilarityResult = {
    tier: 'category_size' | 'brand_category' | 'category' | null;
    skus: SimilarSku[];
};

export type CannibalizationCandidate = {
    sku_a_id: number;
    sku_a: string;
    product_a_name: string;
    sku_b_id: number;
    sku_b: string;
    product_b_name: string;
    correlation: number;
};

export type SuccessorCandidate = {
    declining_sku_id: number;
    declining_sku: string;
    declining_product_name: string;
    declining_maturity: string;
    declining_maturity_label: string;
    successor_sku_id: number;
    successor_sku: string;
    successor_product_name: string;
    successor_maturity: string;
    successor_maturity_label: string;
};

export type PriceElasticityRow = {
    sku_id: number;
    sku: string;
    product_name: string;
    distinct_price_points: number;
    elasticity: number;
    interpretation: string;
};

export type PromotionDiscountType = 'PERCENTAGE' | 'FIXED';

export type PromotionState = 'upcoming' | 'active' | 'ended';

export type Promotion = {
    id: number;
    name: string;
    discount_type: PromotionDiscountType;
    discount_type_label: string;
    discount_value: number;
    start_date: string;
    end_date: string;
    state: PromotionState;
    notes: string | null;
    skus_count?: number;
    skus?: { id: number; sku: string; product_name: string | null }[];
    created_at: string | null;
};

export type PromotionImpactSku = {
    sku_id: number;
    sku: string;
    product_name: string | null;
    during_qty: number;
    baseline_qty: number;
    during_daily_rate: number;
    baseline_daily_rate: number;
    percent_change: number | null;
};

export type PromotionImpact = {
    elapsed: boolean;
    skus: PromotionImpactSku[];
};

export type SupplierPerformanceMetric = {
    id: number;
    supplier?: {
        id: number;
        name: string;
        default_lead_time_days: number;
    } | null;
    period_start: string;
    period_end: string;
    ordered_qty: number;
    received_qty: number;
    average_lead_time_days: number | null;
    lead_time_std_dev: number | null;
    on_time_percentage: number | null;
    fill_rate: number | null;
    quality_issue_rate: number | null;
};
