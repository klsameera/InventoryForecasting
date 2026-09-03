<?php

namespace Database\Factories;

use App\Models\MlModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlModelVersion>
 */
class MlModelVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'baseline-moving-average',
            'version' => 'v'.fake()->numberBetween(1, 5),
            'model_type' => 'moving_average',
            'training_start_date' => null,
            'training_end_date' => null,
            'training_rows' => 0,
            'accuracy_metrics' => null,
            'feature_schema_version' => 'v1',
            'model_path' => null,
            'status' => 'active',
        ];
    }
}
