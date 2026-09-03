<?php

namespace Database\Factories;

use App\Enums\ForecastSource;
use App\Models\Forecast;
use App\Models\MlForecastRun;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Forecast>
 */
class ForecastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $predicted = fake()->randomFloat(2, 10, 200);

        return [
            'forecast_run_id' => MlForecastRun::factory(),
            'warehouse_id' => Warehouse::factory(),
            'sku_id' => Sku::factory(),
            'forecast_date' => now()->addDays(30),
            'horizon_days' => 30,
            'predicted_qty' => $predicted,
            'lower_qty' => round($predicted * 0.7, 2),
            'upper_qty' => round($predicted * 1.3, 2),
            'confidence_score' => fake()->numberBetween(40, 95),
            'model_version_id' => null,
            'forecast_source' => ForecastSource::SkuHistory,
        ];
    }
}
