<?php

namespace App\Models;

use Database\Factories\MlModelVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * app_plan.md §66. Never overwritten — a new row per trained version, so a
 * forecast can always be traced back to exactly what produced it (§67).
 * This scaffold's baseline algorithm isn't "trained" in the ML sense, so it
 * registers one fixed row (name "baseline-moving-average") rather than a
 * growing version history — see MlServiceClient's docblock.
 *
 * @property int $id
 * @property string $name
 * @property string $version
 * @property string $model_type
 * @property Carbon|null $training_start_date
 * @property Carbon|null $training_end_date
 * @property int $training_rows
 * @property array<string, mixed>|null $accuracy_metrics
 * @property string|null $feature_schema_version
 * @property string|null $model_path
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'version', 'model_type', 'training_start_date', 'training_end_date',
    'training_rows', 'accuracy_metrics', 'feature_schema_version', 'model_path', 'status',
])]
class MlModelVersion extends Model
{
    /** @use HasFactory<MlModelVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'training_start_date' => 'date',
            'training_end_date' => 'date',
            'accuracy_metrics' => 'array',
        ];
    }

    /**
     * @return HasMany<Forecast, $this>
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class, 'model_version_id');
    }
}
