<?php

namespace Database\Factories;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'SO-'.fake()->unique()->numerify('######'),
            'warehouse_id' => Warehouse::factory(),
            'customer_name' => fake()->name(),
            'status' => SalesOrderStatus::Draft,
            'order_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'created_by' => null,
        ];
    }
}
