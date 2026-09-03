<?php

declare(strict_types=1);

namespace App\Http\Resources\GoodsReceipt;

use App\Models\GoodsReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GoodsReceipt
 */
final class GoodsReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
                'supplier_name' => $this->purchaseOrder->supplier?->name,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'received_date' => $this->received_date->toDateString(),
            'notes' => $this->notes,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'sku_id' => $item->sku_id,
                'sku' => $item->sku?->sku,
                'product_name' => $item->sku?->product?->name,
                'received_qty' => $item->received_qty,
                'unit_cost' => (float) $item->unit_cost,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
