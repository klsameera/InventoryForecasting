<?php

namespace Database\Factories;

use App\Enums\ForecastRunStatus;
use App\Models\MlForecastRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlForecastRun>
 */
class MlForecastRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_ids' => null,
            'horizon_days' => fake()->randomElement([7, 14, 30, 60, 90, 180]),
            'status' => ForecastRunStatus::Queued,
            'model_version_id' => null,
            'started_at' => null,
            'finished_at' => null,
            'error_message' => null,
            'created_by' => null,
        ];
    }
}
