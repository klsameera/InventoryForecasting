<?php

namespace Database\Factories;

use App\Enums\ReturnCondition;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesReturnItem>
 */
class SalesReturnItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_return_id' => SalesReturn::factory(),
            'sales_order_item_id' => SalesOrderItem::factory(),
            'sku_id' => Sku::factory(),
            'quantity' => fake()->numberBetween(1, 10),
            'condition' => ReturnCondition::Sellable,
        ];
    }
}
