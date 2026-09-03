<?php

declare(strict_types=1);

namespace App\Http\Resources\Sku;

use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sku
 */
final class SkuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'cost_price' => (float) $this->cost_price,
            'selling_price' => (float) $this->selling_price,
            'status' => (bool) $this->status,
            'first_stock_date' => $this->first_stock_date?->toDateString(),
            'last_stock_date' => $this->last_stock_date?->toDateString(),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ] : null),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
            ] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
