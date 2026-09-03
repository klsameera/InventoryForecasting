<?php

declare(strict_types=1);

namespace App\Http\Resources\Promotion;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Promotion
 */
final class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'discount_type' => $this->discount_type->value,
            'discount_type_label' => $this->discount_type->label(),
            'discount_value' => (float) $this->discount_value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'state' => $this->state(),
            'notes' => $this->notes,
            'skus_count' => $this->whenCounted('skus'),
            'skus' => $this->whenLoaded('skus', fn () => $this->skus->map(fn ($sku) => [
                'id' => $sku->id,
                'sku' => $sku->sku,
                'product_name' => $sku->product?->name,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    private function state(): string
    {
        return match (true) {
            $this->start_date->isFuture() => 'upcoming',
            $this->end_date->isPast() => 'ended',
            default => 'active',
        };
    }
}
