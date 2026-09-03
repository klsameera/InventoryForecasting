<?php

namespace Database\Factories;

use App\Enums\PromotionDiscountType;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 months', '-1 month');

        return [
            'name' => rtrim(fake()->sentence(3), '.').' promotion',
            'discount_type' => fake()->randomElement(PromotionDiscountType::cases()),
            'discount_value' => fake()->randomFloat(2, 5, 30),
            'start_date' => $startDate,
            'end_date' => (clone $startDate)->modify('+14 days'),
            'notes' => null,
            'created_by' => null,
        ];
    }
}
