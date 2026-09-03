<?php

declare(strict_types=1);

namespace App\Http\Resources\Forecast;

use App\Models\Forecast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Forecast
 */
final class ForecastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'forecast_run_id' => $this->forecast_run_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'sku' => $this->whenLoaded('sku', fn () => [
                'id' => $this->sku->id,
                'sku' => $this->sku->sku,
                'product_name' => $this->sku->product?->name,
            ]),
            'forecast_date' => $this->forecast_date->toDateString(),
            'horizon_days' => $this->horizon_days,
            'predicted_qty' => (float) $this->predicted_qty,
            'lower_qty' => (float) $this->lower_qty,
            'upper_qty' => (float) $this->upper_qty,
            'confidence_score' => $this->confidence_score,
            'forecast_source' => $this->forecast_source->value,
            'forecast_source_label' => $this->forecast_source->label(),
            'model_version' => $this->whenLoaded('modelVersion', fn () => $this->modelVersion?->name),
            'accuracy' => $this->whenLoaded('accuracy', fn () => $this->accuracy === null ? null : [
                'actual_qty' => (float) $this->accuracy->actual_qty,
                'absolute_error' => (float) $this->accuracy->absolute_error,
                'percentage_error' => $this->accuracy->percentage_error !== null ? (float) $this->accuracy->percentage_error : null,
            ]),
        ];
    }
}
