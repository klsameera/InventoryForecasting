<?php

declare(strict_types=1);

namespace App\Http\Resources\StockMovement;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
final class StockMovementResource extends JsonResource
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
            'movement_type' => $this->movement_type->value,
            'movement_type_label' => $this->movement_type->label(),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'notes' => $this->notes,
            'occurred_at' => $this->occurred_at->toDateTimeString(),
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
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
