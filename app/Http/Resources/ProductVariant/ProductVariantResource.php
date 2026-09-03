<?php

declare(strict_types=1);

namespace App\Http\Resources\ProductVariant;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
final class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'status' => (bool) $this->status,
            'skus_count' => $this->whenCounted('skus'),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ] : null),
            'attribute_values' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->map(fn ($value) => [
                'id' => $value->id,
                'value' => $value->value,
                'attribute_id' => $value->pivot->attribute_id,
                'attribute_name' => $value->relationLoaded('attribute') ? $value->attribute->name : null,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
