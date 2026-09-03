<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'status' => true,
            'default_lead_time_days' => fake()->numberBetween(3, 30),
            'minimum_order_value' => fake()->randomFloat(2, 0, 500),
        ];
    }
}
