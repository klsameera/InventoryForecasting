export type AbcClass = 'a' | 'b' | 'c' | 'unclassified';

export type MovementSpeed = 'fast' | 'slow' | 'dead' | 'not_applicable';

export type AnalyticsRow = {
    warehouse_id: number;
    warehouse_name: string;
    sku_id: number;
    sku: string;
    product_name: string;
    category_name: string;
    on_hand_qty: number;
    available_qty: number;
    units_sold_period: number;
    daily_velocity: number;
    days_of_stock: number | null;
    turnover_ratio: number | null;
    weighted_age_days: number | null;
    revenue_period: number;
    lead_time_days: number | null;
    reorder_point: number | null;
    needs_reorder: boolean;
    abc_class: AbcClass;
    abc_class_label: string;
    movement_speed: MovementSpeed;
    movement_speed_label: string;
};
