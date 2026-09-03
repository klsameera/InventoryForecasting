<?php

declare(strict_types=1);

namespace App\Http\Resources\InventoryDailySnapshot;

use App\Models\InventoryDailySnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryDailySnapshot
 */
final class InventoryDailySnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'snapshot_date' => $this->snapshot_date->toDateString(),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'sku' => $this->whenLoaded('sku', fn () => [
                'id' => $this->sku->id,
                'sku' => $this->sku->sku,
                'product_name' => $this->sku->product?->name,
            ]),
            'opening_qty' => $this->opening_qty,
            'received_qty' => $this->received_qty,
            'sold_qty' => $this->sold_qty,
            'returned_qty' => $this->returned_qty,
            'transfer_in_qty' => $this->transfer_in_qty,
            'transfer_out_qty' => $this->transfer_out_qty,
            'adjustment_qty' => $this->adjustment_qty,
            'closing_qty' => $this->closing_qty,
            'available_qty' => $this->available_qty,
            'stockout_minutes' => $this->stockout_minutes,
            'stockout_flag' => (bool) $this->stockout_flag,
            'inventory_value' => (float) $this->inventory_value,
        ];
    }
}
