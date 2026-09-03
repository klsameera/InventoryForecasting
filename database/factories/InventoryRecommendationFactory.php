<?php

namespace Database\Factories;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Enums\StockoutRisk;
use App\Models\InventoryRecommendation;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryRecommendation>
 */
class InventoryRecommendationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $forecast30d = fake()->randomFloat(2, 10, 200);

        return [
            'warehouse_id' => Warehouse::factory(),
            'sku_id' => Sku::factory(),
            'forecast_id' => null,
            'recommendation_type' => RecommendationType::Purchase,
            'current_qty' => fake()->numberBetween(0, 50),
            'incoming_qty' => 0,
            'forecast_30d' => $forecast30d,
            'forecast_60d' => round($forecast30d * 2, 2),
            'forecast_90d' => round($forecast30d * 3, 2),
            'recommended_qty' => fake()->numberBetween(10, 200),
            'recommended_action_date' => now()->toDateString(),
            'stockout_risk' => StockoutRisk::Moderate,
            'overstock_risk' => null,
            'ageing_risk' => null,
            'confidence_score' => fake()->numberBetween(40, 95),
            'reason' => 'Available stock is at or below the reorder point.',
            'status' => RecommendationStatus::New,
            'decided_qty' => null,
            'decision_reason' => null,
            'decided_by' => null,
            'decided_at' => null,
        ];
    }
}
