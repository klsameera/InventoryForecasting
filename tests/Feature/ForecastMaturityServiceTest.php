<?php

use App\Enums\ForecastMaturity;
use App\Enums\MovementType;
use App\Models\InventoryDailySnapshot;
use App\Models\Product;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Domain\Facades\ForecastMaturityFacade\ForecastMaturityFacade;

test('a sku with no sales history is cold start', function () {
    $sku = Sku::factory()->create();

    $result = ForecastMaturityFacade::classify($sku->id);

    expect($result['maturity'])->toBe(ForecastMaturity::ColdStart);
    expect($result['days_of_history'])->toBeNull();
});

test('a sku with a sale within the last 30 days is early', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(10),
    ]);

    $result = ForecastMaturityFacade::classify($sku->id);

    expect($result['maturity'])->toBe(ForecastMaturity::Early);
    expect($result['days_of_history'])->toBe(10);
});

test('a sku with 40 days of history and no trend data is established', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(40),
    ]);

    $result = ForecastMaturityFacade::classify($sku->id);

    expect($result['maturity'])->toBe(ForecastMaturity::Established);
});

test('a sku with steady sales for over a year is mature', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(400),
    ]);

    $result = ForecastMaturityFacade::classify($sku->id);

    expect($result['maturity'])->toBe(ForecastMaturity::Mature);
    expect($result['days_of_history'])->toBe(400);
});

test('a sku whose trailing 30 days sold much less than the prior 30 days is declining', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(200),
    ]);

    for ($i = 31; $i <= 60; $i++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($i),
            'sold_qty' => 10,
        ]);
    }

    for ($i = 1; $i <= 30; $i++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($i),
            'sold_qty' => 1,
        ]);
    }

    $result = ForecastMaturityFacade::classify($sku->id);

    expect($result['maturity'])->toBe(ForecastMaturity::Declining);
});

test('a product past its end of life date is classified end of life regardless of sales', function () {
    $product = Product::factory()->create(['end_of_life_date' => now()->subDay()->toDateString()]);
    $sku = Sku::factory()->create(['product_id' => $product->id]);
    $warehouse = Warehouse::factory()->create();

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(400),
    ]);

    $result = ForecastMaturityFacade::classify($sku->id);

    expect($result['maturity'])->toBe(ForecastMaturity::EndOfLife);
});
