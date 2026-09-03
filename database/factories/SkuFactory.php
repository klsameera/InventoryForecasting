<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sku>
 */
class SkuFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'barcode' => fake()->unique()->ean13(),
            'cost_price' => fake()->randomFloat(2, 5, 200),
            'selling_price' => fake()->randomFloat(2, 10, 400),
            'status' => true,
            'first_stock_date' => null,
            'last_stock_date' => null,
        ];
    }
}
