<?php

declare(strict_types=1);

namespace App\Http\Resources\PurchaseOrder;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
final class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'name' => $this->supplier->name]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'order_date' => $this->order_date->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'notes' => $this->notes,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sku_id' => $item->sku_id,
                'sku' => $item->sku?->sku,
                'product_name' => $item->sku?->product?->name,
                'quantity' => $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'received_qty' => $item->received_qty,
                'line_total' => (float) $item->line_total,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
