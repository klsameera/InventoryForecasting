<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'sku_id' => Sku::factory(),
            'movement_type' => MovementType::OpeningStock,
            'quantity' => fake()->numberBetween(1, 100),
            'unit_cost' => fake()->randomFloat(2, 5, 200),
            'reference_type' => null,
            'reference_id' => null,
            'occurred_at' => now(),
            'user_id' => null,
            'notes' => null,
        ];
    }
}
