<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Inventory
 */
final class InventoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'sku_id' => $this->sku_id,
            'on_hand_qty' => $this->on_hand_qty,
            'reserved_qty' => $this->reserved_qty,
            'available_qty' => $this->available_qty,
            'incoming_qty' => $this->incoming_qty,
            'average_cost' => (float) $this->average_cost,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null),
            'sku' => $this->whenLoaded('sku', fn () => $this->sku ? [
                'id' => $this->sku->id,
                'sku' => $this->sku->sku,
                'product' => $this->sku->relationLoaded('product') && $this->sku->product
                    ? ['id' => $this->sku->product->id, 'name' => $this->sku->product->name]
                    : null,
            ] : null),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
