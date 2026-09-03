<?php

declare(strict_types=1);

namespace App\Http\Resources\ForecastRun;

use App\Models\MlForecastRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MlForecastRun
 */
final class ForecastRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_ids' => $this->warehouse_ids,
            'horizon_days' => $this->horizon_days,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'model_version' => $this->whenLoaded('modelVersion', fn () => $this->modelVersion?->name.' '.$this->modelVersion?->version),
            'forecasts_count' => $this->whenCounted('forecasts'),
            'started_at' => $this->started_at?->toDateTimeString(),
            'finished_at' => $this->finished_at?->toDateTimeString(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
