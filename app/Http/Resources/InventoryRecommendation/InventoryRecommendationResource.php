<?php

declare(strict_types=1);

namespace App\Http\Resources\InventoryRecommendation;

use App\Models\InventoryRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryRecommendation
 */
final class InventoryRecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'source_warehouse' => $this->whenLoaded('sourceWarehouse', fn () => $this->sourceWarehouse === null ? null : ['id' => $this->sourceWarehouse->id, 'name' => $this->sourceWarehouse->name]),
            'sku' => $this->whenLoaded('sku', fn () => [
                'id' => $this->sku->id,
                'sku' => $this->sku->sku,
                'product_name' => $this->sku->product?->name,
            ]),
            'recommendation_type' => $this->recommendation_type->value,
            'recommendation_type_label' => $this->recommendation_type->label(),
            'current_qty' => $this->current_qty,
            'incoming_qty' => $this->incoming_qty,
            'forecast_30d' => $this->forecast_30d !== null ? (float) $this->forecast_30d : null,
            'forecast_60d' => $this->forecast_60d !== null ? (float) $this->forecast_60d : null,
            'forecast_90d' => $this->forecast_90d !== null ? (float) $this->forecast_90d : null,
            'recommended_qty' => $this->recommended_qty,
            'recommended_action_date' => $this->recommended_action_date->toDateString(),
            'stockout_risk' => $this->stockout_risk?->value,
            'stockout_risk_label' => $this->stockout_risk?->label(),
            'overstock_risk' => $this->overstock_risk?->value,
            'overstock_risk_label' => $this->overstock_risk?->label(),
            'ageing_risk' => $this->ageing_risk,
            'confidence_score' => $this->confidence_score,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'decided_qty' => $this->decided_qty,
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
