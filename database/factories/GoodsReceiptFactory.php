<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_number' => 'GR-'.fake()->unique()->numerify('######'),
            'purchase_order_id' => PurchaseOrder::factory(),
            'warehouse_id' => Warehouse::factory(),
            'received_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'notes' => null,
            'received_by' => null,
        ];
    }
}
