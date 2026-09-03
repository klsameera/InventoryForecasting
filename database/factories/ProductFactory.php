<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'name' => fake()->unique()->words(3, true),
            'product_type' => ProductType::Simple,
            'model_number' => fake()->bothify('MDL-####'),
            'model_year' => (int) fake()->year(),
            'launch_date' => fake()->date(),
            'end_of_life_date' => null,
            'status' => true,
        ];
    }
}
