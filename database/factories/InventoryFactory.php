<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
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
            'on_hand_qty' => 0,
            'reserved_qty' => 0,
            'available_qty' => 0,
            'incoming_qty' => 0,
            'average_cost' => 0,
        ];
    }
}
