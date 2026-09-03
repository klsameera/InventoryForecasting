export type RecommendationType =
    | 'PURCHASE'
    | 'REDUCE_PURCHASE'
    | 'DO_NOT_REORDER'
    | 'TRANSFER_STOCK'
    | 'PROMOTE'
    | 'DISCOUNT'
    | 'CLEARANCE'
    | 'RETURN_TO_SUPPLIER'
    | 'REVIEW_PRODUCT';

export type RecommendationStatus =
    'NEW' | 'REVIEWED' | 'ACCEPTED' | 'MODIFIED' | 'REJECTED' | 'COMPLETED';

export type StockoutRisk = 'NONE' | 'MODERATE' | 'CRITICAL';

export type OverstockRisk = 'NONE' | 'MODERATE' | 'CRITICAL';

export type CentralAllocationBreakdownRow = {
    warehouse_id: number;
    warehouse_name: string | null;
    recommended_qty: number;
    stockout_risk: StockoutRisk | null;
};

export type CentralAllocationRow = {
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    warehouse_count: number;
    total_recommended_qty: number;
    breakdown: CentralAllocationBreakdownRow[];
};

export type InventoryRecommendation = {
    id: number;
    warehouse?: { id: number; name: string } | null;
    source_warehouse?: { id: number; name: string } | null;
    sku?: { id: number; sku: string; product_name: string | null } | null;
    recommendation_type: RecommendationType;
    recommendation_type_label: string;
    current_qty: number;
    incoming_qty: number;
    forecast_30d: number | null;
    forecast_60d: number | null;
    forecast_90d: number | null;
    recommended_qty: number;
    recommended_action_date: string;
    stockout_risk: StockoutRisk | null;
    stockout_risk_label: string | null;
    overstock_risk: OverstockRisk | null;
    overstock_risk_label: string | null;
    ageing_risk: number | null;
    confidence_score: number | null;
    reason: string | null;
    status: RecommendationStatus;
    status_label: string;
    decided_qty: number | null;
    decision_reason: string | null;
    decided_by: string | null;
    decided_at: string | null;
};
