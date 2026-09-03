<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesReturn>
 */
class SalesReturnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_number' => 'SR-'.fake()->unique()->numerify('######'),
            'sales_order_id' => SalesOrder::factory(),
            'warehouse_id' => Warehouse::factory(),
            'return_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'reason' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
