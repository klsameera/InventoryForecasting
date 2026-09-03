<?php

declare(strict_types=1);

namespace App\Http\Resources\SalesOrder;

use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesOrder
 */
final class SalesOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'customer_name' => $this->customer_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'order_date' => $this->order_date->toDateString(),
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->mapItems()),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, array{id: int, sku_id: int, sku: string|null, product_name: string|null, quantity: int, unit_price: float, discount: float, net_amount: float, cost: float|null}>
     */
    private function mapItems(): array
    {
        return $this->items->map(fn ($item) => [
            'id' => $item->id,
            'sku_id' => $item->sku_id,
            'sku' => $item->sku?->sku,
            'product_name' => $item->sku?->product?->name,
            'quantity' => $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount' => (float) $item->discount,
            'net_amount' => (float) $item->net_amount,
            'cost' => $item->cost !== null ? (float) $item->cost : null,
        ])->all();
    }
}
