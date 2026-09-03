<?php

declare(strict_types=1);

namespace App\Http\Resources\StockTransfer;

use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockTransfer
 */
final class StockTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'source_warehouse_id' => $this->source_warehouse_id,
            'source_warehouse' => $this->whenLoaded('sourceWarehouse', fn () => ['id' => $this->sourceWarehouse->id, 'name' => $this->sourceWarehouse->name]),
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'destination_warehouse' => $this->whenLoaded('destinationWarehouse', fn () => ['id' => $this->destinationWarehouse->id, 'name' => $this->destinationWarehouse->name]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'transfer_date' => $this->transfer_date->toDateString(),
            'notes' => $this->notes,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sku_id' => $item->sku_id,
                'sku' => $item->sku?->sku,
                'product_name' => $item->sku?->product?->name,
                'quantity' => $item->quantity,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
