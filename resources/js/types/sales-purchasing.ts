import type { Option, SkuOption } from '@/types/catalog';

export type PricedSkuOption = SkuOption & {
    product_name: string;
    cost_price: number;
    selling_price: number;
};

export type PurchaseOrderStatus =
    | 'draft'
    | 'approved'
    | 'ordered'
    | 'partially_received'
    | 'received'
    | 'cancelled';

export type StockTransferStatus =
    'draft' | 'approved' | 'dispatched' | 'received' | 'cancelled';

export type SalesOrderStatus = 'draft' | 'confirmed' | 'cancelled';

export type ReturnCondition = 'sellable' | 'damaged';

export type StatusOption<TStatus extends string> = {
    value: TStatus;
    label: string;
};

export type SupplierSku = {
    id: number;
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    supplier_sku: string | null;
    unit_cost: number;
    minimum_order_qty: number;
    order_multiple: number;
    expected_lead_time_days: number | null;
    is_primary: boolean;
    status: boolean;
};

export type Supplier = {
    id: number;
    name: string;
    status: boolean;
    default_lead_time_days: number;
    minimum_order_value: number | null;
    supplier_skus_count?: number;
    supplier_skus?: SupplierSku[];
    created_at: string | null;
    updated_at: string | null;
};

export type PurchaseOrderItem = {
    id: number;
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    quantity: number;
    unit_cost: number;
    received_qty: number;
    line_total: number;
};

export type PurchaseOrder = {
    id: number;
    po_number: string;
    supplier_id: number;
    supplier?: Option | null;
    warehouse_id: number;
    warehouse?: Option | null;
    status: PurchaseOrderStatus;
    status_label: string;
    order_date: string;
    expected_date: string | null;
    notes: string | null;
    subtotal: number;
    tax: number;
    total: number;
    items_count?: number;
    items?: PurchaseOrderItem[];
    created_at: string | null;
    updated_at: string | null;
};

export type PurchaseOrderOption = {
    id: number;
    po_number: string;
    supplier_name: string;
};

export type GoodsReceiptItem = {
    id: number;
    purchase_order_item_id: number;
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    received_qty: number;
    unit_cost: number;
};

export type GoodsReceipt = {
    id: number;
    receipt_number: string;
    purchase_order_id: number;
    purchase_order?: {
        id: number;
        po_number: string;
        supplier_name: string | null;
    } | null;
    warehouse_id: number;
    warehouse?: Option | null;
    received_date: string;
    notes: string | null;
    items_count?: number;
    items?: GoodsReceiptItem[];
    created_at: string | null;
};

export type StockTransferItem = {
    id: number;
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    quantity: number;
};

export type StockTransfer = {
    id: number;
    transfer_number: string;
    source_warehouse_id: number;
    source_warehouse?: Option | null;
    destination_warehouse_id: number;
    destination_warehouse?: Option | null;
    status: StockTransferStatus;
    status_label: string;
    transfer_date: string;
    notes: string | null;
    items_count?: number;
    items?: StockTransferItem[];
    created_at: string | null;
    updated_at: string | null;
};

export type SalesOrderItem = {
    id: number;
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    quantity: number;
    unit_price: number;
    discount: number;
    net_amount: number;
    cost: number | null;
};

export type SalesOrder = {
    id: number;
    order_number: string;
    warehouse_id: number;
    warehouse?: Option | null;
    customer_name: string | null;
    status: SalesOrderStatus;
    status_label: string;
    order_date: string;
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
    items_count?: number;
    items?: SalesOrderItem[];
    created_at: string | null;
    updated_at: string | null;
};

export type SalesOrderOption = {
    id: number;
    order_number: string;
};

export type OutstandingSalesOrderItem = {
    id: number;
    sku_id: number;
    sku: string;
    product_name: string;
    quantity: number;
    returned_qty: number;
    remaining_qty: number;
};

export type SalesReturnItem = {
    id: number;
    sales_order_item_id: number;
    sku_id: number;
    sku: string | null;
    product_name: string | null;
    quantity: number;
    condition: ReturnCondition;
    condition_label: string;
};

export type SalesReturn = {
    id: number;
    return_number: string;
    sales_order_id: number;
    sales_order?: { id: number; order_number: string } | null;
    warehouse_id: number;
    warehouse?: Option | null;
    return_date: string;
    reason: string | null;
    items_count?: number;
    items?: SalesReturnItem[];
    created_at: string | null;
};

export type InventoryBatch = {
    id: number;
    warehouse?: Option | null;
    sku?: { id: number; sku: string; product_name: string | null } | null;
    source_type: string;
    source_id: number;
    received_date: string;
    received_qty: number;
    remaining_qty: number;
    unit_cost: number;
    age_days: number;
    expiry_date: string | null;
};
