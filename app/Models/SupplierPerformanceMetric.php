<?php

namespace App\Models;

use Database\Factories\SupplierPerformanceMetricFactory;
use Domain\Services\SupplierPerformanceService\SupplierPerformanceService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * app_plan.md's `supplier_performance_metrics` schema (documented but never
 * built until Phase 10, app_plan.md §86 "supplier lead-time prediction") —
 * one row per supplier per captured period. A derived/recomputable snapshot,
 * like {@see InventoryDailySnapshot}: never edited by hand, only written by
 * {@see SupplierPerformanceService::capturePeriod()}.
 *
 * `quality_issue_rate` is a real schema column (per app_plan.md) that always
 * stays `null` — nothing in this application tracks a quality/defect signal
 * on a goods receipt, so it is deliberately not fabricated. See the
 * Service's docblock.
 *
 * @property int $id
 * @property int $supplier_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $ordered_qty
 * @property int $received_qty
 * @property float|null $average_lead_time_days
 * @property float|null $lead_time_std_dev
 * @property float|null $on_time_percentage
 * @property float|null $fill_rate
 * @property float|null $quality_issue_rate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'supplier_id', 'period_start', 'period_end', 'ordered_qty', 'received_qty',
    'average_lead_time_days', 'lead_time_std_dev', 'on_time_percentage',
    'fill_rate', 'quality_issue_rate',
])]
class SupplierPerformanceMetric extends Model
{
    /** @use HasFactory<SupplierPerformanceMetricFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'average_lead_time_days' => 'decimal:2',
            'lead_time_std_dev' => 'decimal:2',
            'on_time_percentage' => 'decimal:2',
            'fill_rate' => 'decimal:2',
            'quality_issue_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
