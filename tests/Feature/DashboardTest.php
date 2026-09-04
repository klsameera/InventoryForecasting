<?php

use App\Models\BuyabansDailyDemand;
use App\Models\BuyabansStockLevel;
use App\Models\Category;
use App\Models\InventoryRecommendation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\User;
use Domain\Facades\DashboardFacade\DashboardFacade;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

/**
 * @return array{0: Sku, 1: string}
 */
function seedDashboardDemand(int $days = 30, float $perDay = 10): array
{
    config()->set('services.ml.demand_source', 'buyabans');
    config()->set('services.buyabans.grain', 'warehouse');

    // A fixed, short product name. The factory's is three faker words, which
    // is sometimes 26 characters and sometimes 31 — and the label is trimmed at
    // 28, so an assertion against the full name passed or failed depending on
    // the seed. That is a flaky test, not a passing one.
    $product = Product::factory()->create(['name' => 'Test kettle']);
    $sku = Sku::factory()->create(['product_id' => $product->id]);
    $last = now()->subDay()->startOfDay();

    for ($i = 0; $i < $days; $i++) {
        BuyabansDailyDemand::create([
            'demand_date' => $last->copy()->subDays($i)->toDateString(),
            'grain' => 'warehouse',
            'location_code' => 'DPS45',
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'warehouse_id' => null,
            'sold_qty' => $perDay,
            'revenue' => $perDay * 100,
            'avg_price' => 100,
            'discount_amount' => 0,
            'order_count' => 1,
        ]);
    }

    return [$sku, $last->toDateString()];
}

test('the dashboard reports demand it actually holds', function () {
    [$sku] = seedDashboardDemand(days: 30, perDay: 10);

    $overview = DashboardFacade::overview();

    expect($overview['metrics']['skusTracked']['value'])->toBe(1);
    expect($overview['demandTrend']['labels'])->toHaveCount(12);
    expect(array_sum($overview['demandTrend']['series'][0]['values']))->toBe(300.0);
    // Labelled by product, not by SKU code — many codes here are raw barcodes
    // and a chart axis of them is unreadable.
    expect($overview['topMovers'][0]['label'])->toBe($sku->product->name);
    expect($overview['topMovers'][0]['value'])->toBe(300.0);
});

test('forecast accuracy stays absent until a forecast has been scored', function () {
    seedDashboardDemand();

    // `forecast_accuracy` has never held a row in this application, so there is
    // no accuracy to report. The tile renders an em dash, and a placeholder
    // number would be worse than nothing — nobody re-checks a figure that looks
    // reasonable.
    expect(DashboardFacade::overview()['metrics']['forecastAccuracy'])->toBeNull();
});

test('days of cover is not multiplied by the number of inventory sources', function () {
    [$sku] = seedDashboardDemand(days: 30, perDay: 10);

    // 300 units sold over 30 days is 10/day. 400 in stock is 40 days of cover —
    // but the same SKU is stocked in four sources, and joining demand to stock
    // would count the demand four times and report 10 days instead.
    foreach (['default', 'in_store', 'dutyfree', 'onestop'] as $source) {
        BuyabansStockLevel::create([
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'inventory_source_code' => $source,
            'qty' => 100,
            'synced_at' => now(),
        ]);
    }

    expect(DashboardFacade::overview()['metrics']['stockCoverage']['value'])->toBe(40);
});

test('metrics are absent rather than zero when there is no data at all', function () {
    config()->set('services.ml.demand_source', 'buyabans');

    $overview = DashboardFacade::overview();

    expect($overview['metrics']['skusTracked'])->toBeNull();
    expect($overview['metrics']['stockCoverage'])->toBeNull();
    expect($overview['metrics']['reorderAlerts'])->toBeNull();
    expect($overview['demandTrend']['labels'])->toBe([]);
    expect($overview['topMovers'])->toBe([]);
});

test('reorder alerts count only purchase recommendations awaiting a decision', function () {
    seedDashboardDemand();

    InventoryRecommendation::factory()->count(3)->create([
        'recommendation_type' => 'PURCHASE',
        'status' => 'NEW',
    ]);
    InventoryRecommendation::factory()->create([
        'recommendation_type' => 'PURCHASE',
        'status' => 'ACCEPTED',
    ]);
    InventoryRecommendation::factory()->create([
        'recommendation_type' => 'TRANSFER_STOCK',
        'status' => 'NEW',
    ]);

    expect(DashboardFacade::overview()['metrics']['reorderAlerts']['value'])->toBe(3);
});

test('a second grain does not inflate any figure', function () {
    [$sku] = seedDashboardDemand(days: 30, perDay: 10);

    // The same sales, recorded again at the channel grain. Rows of different
    // grains describe the *same* underlying orders from different angles, so
    // anything that forgets to filter by grain silently doubles. Cheap to
    // write, and the only thing standing between a second grain being synced
    // and every headline figure quietly going wrong.
    $last = now()->subDay()->startOfDay();

    for ($i = 0; $i < 30; $i++) {
        BuyabansDailyDemand::create([
            'demand_date' => $last->copy()->subDays($i)->toDateString(),
            'grain' => 'channel',
            'location_code' => 'default',
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'warehouse_id' => null,
            'sold_qty' => 10,
            'revenue' => 1000,
            'avg_price' => 100,
            'discount_amount' => 0,
            'order_count' => 1,
        ]);
    }

    $overview = DashboardFacade::overview();

    expect($overview['metrics']['skusTracked']['value'])->toBe(1);
    expect(array_sum($overview['demandTrend']['series'][0]['values']))->toBe(300.0);
    expect($overview['topMovers'][0]['value'])->toBe(300.0);
    // Money doubles just as quietly as units do.
    expect($overview['metrics']['revenue']['value'])->toBe(30000.0);
    expect($overview['metrics']['orders']['value'])->toBe(30.0);
});

test('the trading tiles describe the headline window', function () {
    seedDashboardDemand(days: 30, perDay: 10);

    $metrics = DashboardFacade::overview()['metrics'];

    // 30 days x 10 units at 100 each, one order a day.
    expect($metrics['unitsSold']['value'])->toBe(300.0);
    expect($metrics['revenue']['value'])->toBe(30000.0);
    expect($metrics['orders']['value'])->toBe(30.0);

    // Average order value is its own tile rather than a division the reader
    // does in their head: revenue and orders can move in opposite directions,
    // and which one is driving a change is the first question anyone asks.
    expect($metrics['averageOrderValue']['value'])->toBe(1000.0);
});

test('the window says which days it covers', function () {
    seedDashboardDemand(days: 30, perDay: 10);

    $window = DashboardFacade::overview()['window'];
    $last = now()->subDay()->startOfDay();

    // Anchored to the last synced day, never to today — otherwise a sync that
    // falls behind shows a fortnight of zeros with nothing saying why.
    expect($window['to'])->toBe($last->format('d M Y'));
    expect($window['from'])->toBe($last->copy()->subDays(29)->format('d M Y'));
    expect($window['days'])->toBe(30);
});

test('cover bands count each product once and keep their fixed colours', function () {
    config()->set('services.ml.demand_source', 'buyabans');
    config()->set('services.buyabans.grain', 'warehouse');

    $last = now()->subDay()->startOfDay();

    // Same rate of sale, three very different stock positions.
    $stockLevels = ['none' => 0, 'thin' => 50, 'deep' => 3000];
    $skus = [];

    foreach ($stockLevels as $name => $qty) {
        $sku = Sku::factory()->create();
        $skus[$name] = $sku;

        for ($i = 0; $i < 30; $i++) {
            BuyabansDailyDemand::create([
                'demand_date' => $last->copy()->subDays($i)->toDateString(),
                'grain' => 'warehouse',
                'location_code' => 'DPS45',
                'sku_code' => $sku->sku,
                'sku_id' => $sku->id,
                'warehouse_id' => null,
                'sold_qty' => 10,
                'revenue' => 1000,
                'avg_price' => 100,
                'discount_amount' => 0,
                'order_count' => 1,
            ]);
        }

        if ($qty > 0) {
            BuyabansStockLevel::create([
                'sku_code' => $sku->sku,
                'sku_id' => $sku->id,
                'inventory_source_code' => 'default',
                'qty' => $qty,
                'synced_at' => now(),
            ]);
        }
    }

    $overview = DashboardFacade::overview();
    $bands = collect($overview['coverHealth']['items'])->keyBy('label');

    // 10 a day: nothing is out of stock, 50 lasts 5 days, 3000 lasts 300.
    expect($bands['Out of stock']['value'])->toBe(1.0);
    expect($bands['Under 2 weeks']['value'])->toBe(1.0);
    expect($bands['Over 6 months']['value'])->toBe(1.0);
    expect($overview['coverHealth']['skus'])->toBe(3);

    // Empty bands stay on the chart. "Nothing is running low" is an answer,
    // and a chart that changes shape week to week for an invisible reason is
    // harder to read than one with a zero-length bar in it.
    expect($bands['2 weeks – 2 months']['value'])->toBe(0.0);

    // The slot is fixed per band, so a band emptying out never repaints its
    // neighbours.
    expect($bands['Out of stock']['colorSlot'])->toBe(5);
    expect($bands['Under 2 weeks']['colorSlot'])->toBe(1);

    // The tile is the first band of this same distribution, counted once.
    expect($overview['metrics']['outOfStockLines']['value'])->toBe(1);
});

test('category revenue ranks by money while top movers rank by units', function () {
    config()->set('services.ml.demand_source', 'buyabans');
    config()->set('services.buyabans.grain', 'warehouse');

    $last = now()->subDay()->startOfDay();

    // One category shifts a lot of cheap units; the other sells a handful of
    // expensive ones. The two rankings disagree, which is the whole point of
    // showing both.
    $rows = [
        ['category' => 'Pocket money', 'product' => 'Cheap thing', 'qty' => 100, 'revenue' => 100],
        ['category' => 'Big ticket', 'product' => 'Costly thing', 'qty' => 1, 'revenue' => 5000],
    ];

    foreach ($rows as $row) {
        $category = Category::factory()->create(['name' => $row['category']]);
        $product = Product::factory()->create([
            'name' => $row['product'],
            'category_id' => $category->id,
        ]);
        $sku = Sku::factory()->create(['product_id' => $product->id]);

        for ($i = 0; $i < 30; $i++) {
            BuyabansDailyDemand::create([
                'demand_date' => $last->copy()->subDays($i)->toDateString(),
                'grain' => 'warehouse',
                'location_code' => 'DPS45',
                'sku_code' => $sku->sku,
                'sku_id' => $sku->id,
                'warehouse_id' => null,
                'sold_qty' => $row['qty'],
                'revenue' => $row['revenue'],
                'avg_price' => $row['revenue'] / $row['qty'],
                'discount_amount' => 0,
                'order_count' => 1,
            ]);
        }
    }

    $overview = DashboardFacade::overview();

    expect($overview['revenueByCategory'][0]['label'])->toBe('Big ticket');
    expect($overview['revenueByCategory'][0]['value'])->toBe(150000.0);
    expect($overview['topMovers'][0]['label'])->toBe('Cheap thing');
});

test('stock is valued once per product, not once per inventory source', function () {
    seedDashboardDemand(days: 30, perDay: 10);

    $sku = Sku::first();
    $sku->update(['selling_price' => 25]);

    foreach (['default', 'in_store', 'dutyfree', 'onestop'] as $source) {
        BuyabansStockLevel::create([
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'inventory_source_code' => $source,
            'qty' => 100,
            'synced_at' => now(),
        ]);
    }

    // 400 units at 25 is 10,000 — at retail, because `cost_price` is populated
    // for 149 of 10,892 SKUs and a cost valuation would silently describe 1.4%
    // of the catalogue.
    expect(DashboardFacade::overview()['metrics']['stockValue']['value'])->toBe(10000.0);
});

test('the new figures are absent rather than zero when there is no data', function () {
    config()->set('services.ml.demand_source', 'buyabans');

    $overview = DashboardFacade::overview();

    expect($overview['metrics']['revenue'])->toBeNull();
    expect($overview['metrics']['averageOrderValue'])->toBeNull();
    expect($overview['metrics']['outOfStockLines'])->toBeNull();
    expect($overview['metrics']['stockValue'])->toBeNull();
    expect($overview['coverHealth'])->toBeNull();
    expect($overview['window'])->toBeNull();
    expect($overview['revenueByCategory'])->toBe([]);
    expect($overview['demandByLocation'])->toBe([]);
});

test('a date window includes its own last day', function () {
    config()->set('services.ml.demand_source', 'buyabans');
    config()->set('services.buyabans.grain', 'warehouse');

    $sku = Sku::factory()->create();
    $last = now()->subDay()->startOfDay();

    // Exactly two days: the last synced day, and the one before it.
    foreach ([0, 1] as $daysAgo) {
        BuyabansDailyDemand::create([
            'demand_date' => $last->copy()->subDays($daysAgo)->toDateString(),
            'grain' => 'warehouse',
            'location_code' => 'DPS45',
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'warehouse_id' => null,
            'sold_qty' => 10,
            'revenue' => 1000,
            'avg_price' => 100,
            'discount_amount' => 0,
            'order_count' => 1,
        ]);
    }

    // 20, not 10. The window ends *on* the last synced day, and that day's
    // sales are inside it.
    //
    // This is the trap `whereDate()` was here to avoid, and the reason it could
    // not simply be deleted for the index it was costing. `demand_date` is a
    // real MySQL DATE column, so `<= '2026-09-04'` is exact in production and
    // this test would pass there whatever the code did. On SQLite — which is
    // what this suite runs on — Laravel stores the 'date' cast as
    // '2026-09-04 00:00:00' and compares it as text, where
    // `'2026-09-04 00:00:00' <= '2026-09-04'` is false and the last day
    // silently vanishes. Only the engine the tests do not use would have caught
    // a mistake here; only the engine production does not use can catch this
    // one. Both need to be right, so the assertion lives where it fails loudly.
    expect(DashboardFacade::overview()['metrics']['unitsSold']['value'])->toBe(20.0);
});
