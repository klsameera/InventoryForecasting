<?php

declare(strict_types=1);

namespace App\Http\Resources\SupplierPerformanceMetric;

use App\Models\SupplierPerformanceMetric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierPerformanceMetric
 */
final class SupplierPerformanceMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'default_lead_time_days' => $this->supplier->default_lead_time_days,
            ]),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'ordered_qty' => $this->ordered_qty,
            'received_qty' => $this->received_qty,
            'average_lead_time_days' => $this->average_lead_time_days !== null ? (float) $this->average_lead_time_days : null,
            'lead_time_std_dev' => $this->lead_time_std_dev !== null ? (float) $this->lead_time_std_dev : null,
            'on_time_percentage' => $this->on_time_percentage !== null ? (float) $this->on_time_percentage : null,
            'fill_rate' => $this->fill_rate !== null ? (float) $this->fill_rate : null,
            'quality_issue_rate' => $this->quality_issue_rate !== null ? (float) $this->quality_issue_rate : null,
        ];
    }
}
