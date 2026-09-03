<?php

declare(strict_types=1);

namespace App\Http\Resources\Attribute;

use App\Models\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attribute
 */
final class AttributeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'data_type' => $this->data_type,
            'forecast_relevant' => (bool) $this->forecast_relevant,
            'values_count' => $this->whenCounted('values'),
            'values' => $this->whenLoaded('values', fn () => $this->values->map(fn ($value) => [
                'id' => $value->id,
                'value' => $value->value,
                'sort_order' => $value->sort_order,
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
