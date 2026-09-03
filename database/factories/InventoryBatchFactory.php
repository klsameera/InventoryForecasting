<?php

namespace Database\Factories;

use App\Models\InventoryBatch;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryBatch>
 */
class InventoryBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $receivedQty = fake()->numberBetween(10, 200);

        return [
            'warehouse_id' => Warehouse::factory(),
            'sku_id' => Sku::factory(),
            'source_type' => 'goods_receipt',
            'source_id' => 1,
            'received_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'received_qty' => $receivedQty,
            'remaining_qty' => $receivedQty,
            'unit_cost' => fake()->randomFloat(2, 5, 200),
            'expiry_date' => null,
        ];
    }
}
