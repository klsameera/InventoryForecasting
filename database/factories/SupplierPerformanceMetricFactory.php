<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\SupplierPerformanceMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPerformanceMetric>
 */
class SupplierPerformanceMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-6 months', '-1 month')->modify('first day of this month');
        $ordered = fake()->numberBetween(50, 500);
        $received = fake()->numberBetween(0, $ordered);

        return [
            'supplier_id' => Supplier::factory(),
            'period_start' => $periodStart,
            'period_end' => (clone $periodStart)->modify('last day of this month'),
            'ordered_qty' => $ordered,
            'received_qty' => $received,
            'average_lead_time_days' => fake()->randomFloat(2, 3, 30),
            'lead_time_std_dev' => fake()->randomFloat(2, 0, 5),
            'on_time_percentage' => fake()->randomFloat(2, 40, 100),
            'fill_rate' => $ordered > 0 ? round($received / $ordered * 100, 2) : null,
            'quality_issue_rate' => null,
        ];
    }
}
