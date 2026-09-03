<?php

namespace Database\Factories;

use App\Enums\StockTransferStatus;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transfer_number' => 'TRF-'.fake()->unique()->numerify('######'),
            'source_warehouse_id' => Warehouse::factory(),
            'destination_warehouse_id' => Warehouse::factory(),
            'status' => StockTransferStatus::Draft,
            'transfer_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'notes' => null,
            'created_by' => null,
        ];
    }
}
