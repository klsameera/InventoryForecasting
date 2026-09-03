<?php

namespace App\Models;

use App\Enums\ForecastSource;
use App\Jobs\RunDemandForecast;
use Database\Factories\ForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * app_plan.md §38 — never edited after creation, one row per
 * warehouse/SKU/horizon produced by a forecast run. Written only by
 * {@see RunDemandForecast}.
 *
 * @property int $id
 * @property int $forecast_run_id
 * @property int $warehouse_id
 * @property int $sku_id
 * @property Carbon $forecast_date
 * @property int $horizon_days
 * @property float $predicted_qty
 * @property float $lower_qty
 * @property float $upper_qty
 * @property int $confidence_score
 * @property int|null $model_version_id
 * @property ForecastSource $forecast_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'forecast_run_id', 'warehouse_id', 'sku_id', 'forecast_date', 'horizon_days',
    'predicted_qty', 'lower_qty', 'upper_qty', 'confidence_score', 'model_version_id', 'forecast_source',
])]
class Forecast extends Model
{
    /** @use HasFactory<ForecastFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forecast_date' => 'date',
            'predicted_qty' => 'decimal:2',
            'lower_qty' => 'decimal:2',
            'upper_qty' => 'decimal:2',
            'forecast_source' => ForecastSource::class,
        ];
    }

    /**
     * @return BelongsTo<MlForecastRun, $this>
     */
    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(MlForecastRun::class, 'forecast_run_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return BelongsTo<MlModelVersion, $this>
     */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'model_version_id');
    }

    /**
     * @return HasOne<ForecastAccuracy, $this>
     */
    public function accuracy(): HasOne
    {
        return $this->hasOne(ForecastAccuracy::class);
    }
}
