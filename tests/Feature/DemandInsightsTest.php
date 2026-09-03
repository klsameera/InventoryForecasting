<?php

use App\Models\InventoryDailySnapshot;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\DemandInsightsFacade\DemandInsightsFacade;

test('guests are redirected to the login page for lost sales', function () {
    $response = $this->get(route('demand-insights.lost-sales'));
    $response->assertRedirect(route('login'));
});

test('guests are redirected to the login page for anomalies', function () {
    $response = $this->get(route('demand-insights.anomalies'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view both demand insights pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('demand-insights.lost-sales'))->assertOk();
    $this->actingAs($user)->get(route('demand-insights.anomalies'))->assertOk();
});

test('estimates lost sales from stockout minutes and the normal in-stock daily rate', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    // Three normal (in-stock) days averaging 10/day.
    foreach ([10, 10, 10] as $offset => $qty) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($offset + 1),
            'sold_qty' => $qty,
            'stockout_flag' => false,
            'stockout_minutes' => null,
        ]);
    }

    // One stockout day, out for exactly half the day (720 minutes).
    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'snapshot_date' => now()->subDays(4),
        'sold_qty' => 3,
        'stockout_flag' => true,
        'stockout_minutes' => 720,
    ]);

    // Hand-computed: normal daily rate = avg(10,10,10) = 10. Lost units =
    // (720/1440) * 10 = 5.0.
    $rows = DemandInsightsFacade::lostSales();

    expect($rows->total())->toBe(1);
    $row = $rows->items()[0];
    expect($row->warehouse_id)->toBe($warehouse->id);
    expect($row->sku_id)->toBe($sku->id);
    expect($row->stockout_days)->toBe(1);
    expect($row->normal_daily_rate)->toBe(10.0);
    expect($row->estimated_lost_units)->toBe(5.0);
});

test('a pair with no in-stock days at all is excluded, not given a guessed rate', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 0,
        'stockout_flag' => true,
        'stockout_minutes' => 1440,
    ]);

    $rows = DemandInsightsFacade::lostSales();

    expect($rows->total())->toBe(0);
});

test('a pair with no stockout days at all is excluded — nothing lost to estimate', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 10,
        'stockout_flag' => false,
        'stockout_minutes' => null,
    ]);

    $rows = DemandInsightsFacade::lostSales();

    expect($rows->total())->toBe(0);
});

test('flags a day whose demand is a real statistical outlier for that sku', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    foreach (range(1, 9) as $offset) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($offset),
            'sold_qty' => 10,
            'stockout_flag' => false,
        ]);
    }

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'snapshot_date' => now()->subDays(10),
        'sold_qty' => 1000,
        'stockout_flag' => false,
    ]);

    // Hand-computed: mean = (9*10 + 1000)/10 = 109, sample std-dev ≈
    // 313.06. The 1000 day's z-score ≈ 2.85, clearing the 2.0 threshold;
    // every normal day's z-score ≈ -0.32, nowhere near it.
    $rows = DemandInsightsFacade::anomalies();

    expect($rows->total())->toBe(1);
    $row = $rows->items()[0];
    expect($row->actual_qty)->toBe(1000);
    expect($row->z_score)->toBeGreaterThanOrEqual(2.0);
});

test('a pair with zero variability has no anomalies at all', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    foreach (range(1, 5) as $offset) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($offset),
            'sold_qty' => 10,
            'stockout_flag' => false,
        ]);
    }

    $rows = DemandInsightsFacade::anomalies();

    expect($rows->total())->toBe(0);
});
