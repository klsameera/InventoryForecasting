<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'name' => $this->name,
            'product_type' => $this->product_type->value,
            'model_number' => $this->model_number,
            'model_year' => $this->model_year,
            'launch_date' => $this->launch_date?->toDateString(),
            'end_of_life_date' => $this->end_of_life_date?->toDateString(),
            'status' => (bool) $this->status,
            'variants_count' => $this->whenCounted('variants'),
            'skus_count' => $this->whenCounted('skus'),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
