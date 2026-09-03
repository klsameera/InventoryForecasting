<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferItem>
 */
class StockTransferItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'sku_id' => Sku::factory(),
            'quantity' => fake()->numberBetween(1, 100),
        ];
    }
}
