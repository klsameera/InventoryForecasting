<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SupplierSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierSku>
 */
class SupplierSkuFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'sku_id' => Sku::factory(),
            'supplier_sku' => fake()->bothify('SUP-#####'),
            'unit_cost' => fake()->randomFloat(2, 5, 200),
            'minimum_order_qty' => fake()->numberBetween(1, 20),
            'order_multiple' => 1,
            'expected_lead_time_days' => fake()->numberBetween(3, 30),
            'is_primary' => false,
            'status' => true,
        ];
    }
}
