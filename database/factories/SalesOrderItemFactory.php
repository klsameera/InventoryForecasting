<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderItem>
 */
class SalesOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 20);
        $unitPrice = fake()->randomFloat(2, 10, 400);

        return [
            'sales_order_id' => SalesOrder::factory(),
            'sku_id' => Sku::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'net_amount' => $quantity * $unitPrice,
            'cost' => null,
        ];
    }
}
