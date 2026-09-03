<?php

namespace App\Models;

use App\Enums\ForecastRunStatus;
use App\Jobs\RunDemandForecast;
use Database\Factories\MlForecastRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One row per triggered forecast batch (app_plan.md §34, §67) — QUEUED →
 * PROCESSING → COMPLETED/FAILED, driven by the queued
 * {@see RunDemandForecast} job, never mutated directly from a
 * controller.
 *
 * @property int $id
 * @property array<int, int>|null $warehouse_ids
 * @property int $horizon_days
 * @property ForecastRunStatus $status
 * @property int|null $model_version_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error_message
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['warehouse_ids', 'horizon_days', 'status', 'model_version_id', 'started_at', 'finished_at', 'error_message', 'created_by'])]
class MlForecastRun extends Model
{
    /** @use HasFactory<MlForecastRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'warehouse_ids' => 'array',
            'status' => ForecastRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MlModelVersion, $this>
     */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'model_version_id');
    }

    /**
     * @return HasMany<Forecast, $this>
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class, 'forecast_run_id');
    }
}
