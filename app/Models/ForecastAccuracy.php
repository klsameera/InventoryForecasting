<?php

namespace App\Models;

use Database\Factories\ForecastAccuracyFactory;
use Domain\Services\ForecastService\ForecastService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * app_plan.md §39 — one row per forecast once its forecast_date has passed
 * and the actual sold_qty is known (read from
 * inventory_daily_snapshots — Phase 4). Written only by
 * {@see ForecastService::scoreAccuracy()}.
 *
 * @property int $id
 * @property int $forecast_id
 * @property float $actual_qty
 * @property float $absolute_error
 * @property float|null $percentage_error
 * @property Carbon $calculated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['forecast_id', 'actual_qty', 'absolute_error', 'percentage_error', 'calculated_at'])]
class ForecastAccuracy extends Model
{
    /** @use HasFactory<ForecastAccuracyFactory> */
    use HasFactory;

    /**
     * Eloquent's convention would pluralize this to "forecast_accuracies";
     * the migration deliberately keeps app_plan.md §39's literal table name
     * ("forecast_accuracy", already singular) instead.
     */
    protected $table = 'forecast_accuracy';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actual_qty' => 'decimal:2',
            'absolute_error' => 'decimal:2',
            'percentage_error' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Forecast, $this>
     */
    public function forecast(): BelongsTo
    {
        return $this->belongsTo(Forecast::class);
    }
}
