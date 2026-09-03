<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 100);
        $unitCost = fake()->randomFloat(2, 5, 200);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'sku_id' => Sku::factory(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'received_qty' => 0,
            'line_total' => $quantity * $unitCost,
        ];
    }
}
