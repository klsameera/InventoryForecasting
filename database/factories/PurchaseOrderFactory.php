<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.fake()->unique()->numerify('######'),
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => PurchaseOrderStatus::Draft,
            'order_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'expected_date' => null,
            'notes' => null,
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'created_by' => null,
        ];
    }
}
