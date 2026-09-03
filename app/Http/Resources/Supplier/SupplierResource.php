<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
final class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => (bool) $this->status,
            'default_lead_time_days' => $this->default_lead_time_days,
            'minimum_order_value' => $this->minimum_order_value !== null ? (float) $this->minimum_order_value : null,
            'supplier_skus_count' => $this->whenCounted('supplierSkus'),
            'supplier_skus' => $this->whenLoaded('supplierSkus', fn () => $this->supplierSkus->map(fn ($row) => [
                'id' => $row->id,
                'sku_id' => $row->sku_id,
                'sku' => $row->sku?->sku,
                'product_name' => $row->sku?->product?->name,
                'supplier_sku' => $row->supplier_sku,
                'unit_cost' => (float) $row->unit_cost,
                'minimum_order_qty' => $row->minimum_order_qty,
                'order_multiple' => $row->order_multiple,
                'expected_lead_time_days' => $row->expected_lead_time_days,
                'is_primary' => (bool) $row->is_primary,
                'status' => (bool) $row->status,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
