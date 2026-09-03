<?php

declare(strict_types=1);

namespace App\Http\Resources\SalesReturn;

use App\Models\SalesReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesReturn
 */
final class SalesReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => ['id' => $this->salesOrder->id, 'order_number' => $this->salesOrder->order_number]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'return_date' => $this->return_date->toDateString(),
            'reason' => $this->reason,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'sku_id' => $item->sku_id,
                'sku' => $item->sku?->sku,
                'product_name' => $item->sku?->product?->name,
                'quantity' => $item->quantity,
                'condition' => $item->condition->value,
                'condition_label' => $item->condition->label(),
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
