<?php

namespace App\Models;

use App\Enums\OverstockRisk;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Enums\StockoutRisk;
use Database\Factories\InventoryRecommendationFactory;
use Domain\Services\InventoryRecommendationService\InventoryRecommendationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * app_plan.md §52 — written only by
 * {@see InventoryRecommendationService}. `ageing_risk` is a 0–100 score
 * (app_plan.md §49) stored in a plain nullable string column and cast to
 * `integer` here rather than migrated to a numeric column type — the
 * column has never held data before Phase 8, so this was a safe, additive
 * choice over an ALTER migration for a column nothing else depends on.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int|null $source_warehouse_id
 * @property int $sku_id
 * @property int|null $forecast_id
 * @property RecommendationType $recommendation_type
 * @property int $current_qty
 * @property int $incoming_qty
 * @property float|null $forecast_30d
 * @property float|null $forecast_60d
 * @property float|null $forecast_90d
 * @property int $recommended_qty
 * @property Carbon $recommended_action_date
 * @property StockoutRisk|null $stockout_risk
 * @property OverstockRisk|null $overstock_risk
 * @property int|null $ageing_risk
 * @property int|null $confidence_score
 * @property string|null $reason
 * @property RecommendationStatus $status
 * @property int|null $decided_qty
 * @property string|null $decision_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'warehouse_id', 'source_warehouse_id', 'sku_id', 'forecast_id', 'recommendation_type',
    'current_qty', 'incoming_qty', 'forecast_30d', 'forecast_60d', 'forecast_90d',
    'recommended_qty', 'recommended_action_date', 'stockout_risk', 'overstock_risk',
    'ageing_risk', 'confidence_score', 'reason', 'status', 'decided_qty',
    'decision_reason', 'decided_by', 'decided_at',
])]
class InventoryRecommendation extends Model
{
    /** @use HasFactory<InventoryRecommendationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommendation_type' => RecommendationType::class,
            'forecast_30d' => 'decimal:2',
            'forecast_60d' => 'decimal:2',
            'forecast_90d' => 'decimal:2',
            'recommended_action_date' => 'date',
            'stockout_risk' => StockoutRisk::class,
            'overstock_risk' => OverstockRisk::class,
            'ageing_risk' => 'integer',
            'status' => RecommendationStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The surplus warehouse a {@see RecommendationType::TransferStock} row
     * would move stock from — null for every other recommendation type.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<Forecast, $this>
     */
    public function forecast(): BelongsTo
    {
        return $this->belongsTo(Forecast::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
