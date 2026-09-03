<?php

declare(strict_types=1);

namespace App\Http\Resources\InventoryBatch;

use App\Models\InventoryBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryBatch
 */
final class InventoryBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'sku' => $this->whenLoaded('sku', fn () => [
                'id' => $this->sku->id,
                'sku' => $this->sku->sku,
                'product_name' => $this->sku->product?->name,
            ]),
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'received_date' => $this->received_date->toDateString(),
            'received_qty' => $this->received_qty,
            'remaining_qty' => $this->remaining_qty,
            'unit_cost' => (float) $this->unit_cost,
            'age_days' => (int) $this->received_date->diffInDays(now()),
            'expiry_date' => $this->expiry_date?->toDateString(),
        ];
    }
}
