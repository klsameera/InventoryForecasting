export type Option = {
    id: number;
    name: string;
};

export type ProductType = 'simple' | 'configurable';

export type MovementType =
    | 'PURCHASE_RECEIPT'
    | 'SALE'
    | 'SALE_RETURN'
    | 'PURCHASE_RETURN'
    | 'TRANSFER_IN'
    | 'TRANSFER_OUT'
    | 'ADJUSTMENT_IN'
    | 'ADJUSTMENT_OUT'
    | 'DAMAGE'
    | 'WRITE_OFF'
    | 'OPENING_STOCK';

export type MovementTypeOption = {
    value: MovementType;
    label: string;
};

export type Warehouse = {
    id: number;
    name: string;
    code: string;
    address: string | null;
    status: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type Brand = {
    id: number;
    name: string;
    code: string;
    status: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type Category = {
    id: number;
    parent_id: number | null;
    name: string;
    code: string;
    status: boolean;
    parent?: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
};

export type AttributeValue = {
    id: number;
    value: string;
    sort_order: number;
};

export type Attribute = {
    id: number;
    name: string;
    code: string;
    data_type: string;
    forecast_relevant: boolean;
    values_count?: number;
    values?: AttributeValue[];
    created_at: string | null;
    updated_at: string | null;
};

export type AttributeOption = {
    id: number;
    name: string;
    values: { id: number; value: string }[];
};

export type Product = {
    id: number;
    category_id: number;
    brand_id: number | null;
    name: string;
    product_type: ProductType;
    model_number: string | null;
    model_year: number | null;
    launch_date: string | null;
    end_of_life_date: string | null;
    status: boolean;
    variants_count?: number;
    skus_count?: number;
    category?: { id: number; name: string } | null;
    brand?: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
};

export type VariantAttributeValue = {
    id: number;
    value: string;
    attribute_id: number;
    attribute_name: string | null;
};

export type ProductVariant = {
    id: number;
    product_id: number;
    name: string;
    status: boolean;
    skus_count?: number;
    product?: { id: number; name: string } | null;
    attribute_values?: VariantAttributeValue[];
    created_at: string | null;
    updated_at: string | null;
};

export type VariantOption = {
    id: number;
    product_id: number;
    name: string;
};

export type Sku = {
    id: number;
    product_id: number;
    product_variant_id: number | null;
    sku: string;
    barcode: string | null;
    cost_price: number;
    selling_price: number;
    status: boolean;
    first_stock_date: string | null;
    last_stock_date: string | null;
    product?: { id: number; name: string } | null;
    variant?: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
};

export type SkuOption = {
    id: number;
    sku: string;
};

export type Inventory = {
    id: number;
    warehouse_id: number;
    sku_id: number;
    on_hand_qty: number;
    reserved_qty: number;
    available_qty: number;
    incoming_qty: number;
    average_cost: number;
    warehouse?: { id: number; name: string } | null;
    sku?: {
        id: number;
        sku: string;
        product: { id: number; name: string } | null;
    } | null;
    updated_at: string | null;
};

export type StockMovement = {
    id: number;
    warehouse_id: number;
    sku_id: number;
    movement_type: MovementType;
    movement_type_label: string;
    quantity: number;
    unit_cost: number | null;
    notes: string | null;
    occurred_at: string | null;
    warehouse?: { id: number; name: string } | null;
    sku?: {
        id: number;
        sku: string;
        product: { id: number; name: string } | null;
    } | null;
    user?: { id: number; name: string } | null;
    created_at: string | null;
};
