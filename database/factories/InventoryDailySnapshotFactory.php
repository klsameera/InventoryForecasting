<?php

namespace Database\Factories;

use App\Models\InventoryDailySnapshot;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryDailySnapshot>
 */
class InventoryDailySnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $opening = fake()->numberBetween(0, 100);
        $received = fake()->numberBetween(0, 20);
        $sold = fake()->numberBetween(0, 20);
        $closing = max(0, $opening + $received - $sold);

        return [
            'snapshot_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'warehouse_id' => Warehouse::factory(),
            'sku_id' => Sku::factory(),
            'opening_qty' => $opening,
            'received_qty' => $received,
            'sold_qty' => $sold,
            'returned_qty' => 0,
            'transfer_in_qty' => 0,
            'transfer_out_qty' => 0,
            'adjustment_qty' => 0,
            'closing_qty' => $closing,
            'available_qty' => $closing,
            'stockout_minutes' => $closing === 0 ? fake()->numberBetween(1, 1440) : null,
            'stockout_flag' => $closing === 0,
            'inventory_value' => $closing * fake()->randomFloat(2, 5, 50),
        ];
    }
}
