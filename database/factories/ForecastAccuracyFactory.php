<?php

namespace Database\Factories;

use App\Models\Forecast;
use App\Models\ForecastAccuracy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastAccuracy>
 */
class ForecastAccuracyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actual = fake()->randomFloat(2, 10, 200);

        return [
            'forecast_id' => Forecast::factory(),
            'actual_qty' => $actual,
            'absolute_error' => fake()->randomFloat(2, 0, 20),
            'percentage_error' => fake()->randomFloat(2, 0, 40),
            'calculated_at' => now(),
        ];
    }
}
