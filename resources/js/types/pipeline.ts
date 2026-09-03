import type { Option } from '@/types/catalog';

export type InventoryDailySnapshot = {
    id: number;
    snapshot_date: string;
    warehouse?: Option | null;
    sku?: { id: number; sku: string; product_name: string | null } | null;
    opening_qty: number;
    received_qty: number;
    sold_qty: number;
    returned_qty: number;
    transfer_in_qty: number;
    transfer_out_qty: number;
    adjustment_qty: number;
    closing_qty: number;
    available_qty: number;
    stockout_minutes: number | null;
    stockout_flag: boolean;
    inventory_value: number;
};
