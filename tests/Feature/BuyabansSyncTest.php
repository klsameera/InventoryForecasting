<?php

use App\Models\Brand;
use App\Models\BuyabansDailyDemand;
use App\Models\BuyabansStockLevel;
use App\Models\BuyabansSyncRun;
use App\Models\Category;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\BuyabansSyncFacade\BuyabansSyncFacade;
use Domain\Facades\MlTrainingDataFacade\MlTrainingDataFacade;
use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Domain\Services\ForecastRunService\ForecastRunService;
use Domain\Services\MlServiceClient\MlServiceClient;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The sync is faked at the HTTP boundary rather than against a live back
 * office: what is being tested is this side's mapping, idempotence and failure
 * recording, none of which should depend on another system being up.
 */
beforeEach(function () {
    config()->set('services.buyabans.url', 'http://backoffice.test');
    config()->set('services.buyabans.client_id', 'test-client');
    config()->set('services.buyabans.client_secret', 'test-secret');
    config()->set('services.buyabans.grain', 'warehouse');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fakeBuyabans(array $overrides = []): array
{
    $responses = [
        'http://backoffice.test/oauth/token' => Http::response([
            'access_token' => 'token-123',
            'expires_in' => 3600,
        ]),
        'http://backoffice.test/api/forecasting/locations*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'warehouses' => [
                    ['id' => 1, 'name' => 'Colombo Warehouse', 'location_code' => 'DPS45', 'address' => 'Colombo'],
                    ['id' => 2, 'name' => 'Galle Warehouse', 'location_code' => 'DPS69', 'address' => 'Galle'],
                ],
                'channels' => [['id' => 1, 'code' => 'default']],
                'inventory_sources' => [['id' => 1, 'code' => 'default', 'name' => 'Default']],
            ],
        ]),
        'http://backoffice.test/api/forecasting/categories*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [
                    ['id' => 10, 'parent_id' => null, 'name' => 'Appliances', 'status' => 1],
                    ['id' => 11, 'parent_id' => 10, 'name' => 'Refrigerators', 'status' => 1],
                ],
                'meta' => ['count' => 2, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
        'http://backoffice.test/api/forecasting/brands*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => ['items' => [['id' => 5, 'name' => 'Abans', 'admin_name' => 'Abans']]],
        ]),
        'http://backoffice.test/api/forecasting/products*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'product_id' => 100,
                    'sku' => 'ABTVL32T1',
                    'name' => 'Abans 32 Inch TV',
                    'type' => 'simple',
                    'status' => 1,
                    'price' => '45999.0000',
                    'brand_name' => 'Abans',
                    'categories' => [['id' => 11, 'name' => 'Refrigerators']],
                ]],
                'meta' => ['count' => 1, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
        'http://backoffice.test/api/forecasting/inventory*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'id' => 1,
                    'product_id' => 100,
                    'sku' => 'ABTVL32T1',
                    'inventory_source_id' => 1,
                    'inventory_source_code' => 'default',
                    'qty' => 42,
                ]],
                'meta' => ['count' => 1, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
        'http://backoffice.test/api/forecasting/sales-daily*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'date' => '2026-08-01',
                    'sku' => 'ABTVL32T1',
                    'location_code' => 'DPS45',
                    'warehouse_id' => 1,
                    'sold_qty' => 3,
                    'revenue' => 137997,
                    'order_count' => 2,
                    'avg_price' => 45999,
                    'discount_amount' => 0,
                    'grain' => 'warehouse',
                ]],
                'meta' => ['grain' => 'warehouse', 'count' => 1, 'next_offset' => null, 'has_more' => false],
            ],
        ]),
        'http://backoffice.test/api/forecasting/meta*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'counts' => ['sellable_products' => 10983, 'orders' => 55, 'demand_orders' => 5],
                'orders' => ['first_order_at' => '2026-05-12T15:43:48+05:30', 'last_order_at' => '2026-09-02T02:15:52+05:30'],
                'demand' => ['first_order_at' => null, 'last_order_at' => null],
            ],
        ]),
    ];

    Http::fake(array_merge($responses, $overrides));

    return $responses;
}

test('guests are redirected to the login page', function () {
    $this->get(route('buyabans-sync.index'))->assertRedirect(route('login'));
});

test('there are no create, edit, update or delete routes for the sync', function () {
    expect(Route::has('buyabans-sync.create'))->toBeFalse();
    expect(Route::has('buyabans-sync.edit'))->toBeFalse();
    expect(Route::has('buyabans-sync.update'))->toBeFalse();
    expect(Route::has('buyabans-sync.delete'))->toBeFalse();
});

test('authenticated users can view the sync page with no data', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('buyabans-sync.index'))
        ->assertOk();
});

test('a full sync imports locations, catalog, stock and demand', function () {
    fakeBuyabans();

    $result = BuyabansSyncFacade::syncAll();

    expect($result['success'])->toBeTrue();

    expect(Warehouse::where('code', 'DPS45')->exists())->toBeTrue();
    expect(Category::where('code', 'bab-c10')->exists())->toBeTrue();
    expect(Brand::where('code', 'bab-b5')->exists())->toBeTrue();

    $sku = Sku::where('sku', 'ABTVL32T1')->first();
    expect($sku)->not->toBeNull();
    expect((float) $sku->selling_price)->toBe(45999.0);

    // The product's category resolves through the synced category, and its
    // brand through the brand label the product feed reports.
    expect($sku->product->category->code)->toBe('bab-c11');
    expect($sku->product->brand->name)->toBe('Abans');

    expect(BuyabansStockLevel::where('sku_code', 'ABTVL32T1')->value('qty'))->toBe(42);

    $demand = BuyabansDailyDemand::first();
    expect($demand->sku_code)->toBe('ABTVL32T1');
    expect((float) $demand->sold_qty)->toBe(3.0);
    expect($demand->sku_id)->toBe($sku->id);
    expect($demand->warehouse_id)->toBe(Warehouse::where('code', 'DPS45')->value('id'));
});

test('a child category is linked to its parent in the second pass', function () {
    fakeBuyabans();

    BuyabansSyncFacade::syncCategories();

    $parent = Category::where('code', 'bab-c10')->first();
    $child = Category::where('code', 'bab-c11')->first();

    expect($child->parent_id)->toBe($parent->id);
});

test('re-running a sync upserts rather than duplicating', function () {
    fakeBuyabans();

    BuyabansSyncFacade::syncAll();
    BuyabansSyncFacade::syncAll();

    // The whole design rests on this: the nightly schedule re-pulls an
    // overlapping window every night, and a duplicate demand row would double
    // that day's measured demand.
    expect(BuyabansDailyDemand::count())->toBe(1);
    expect(Sku::where('sku', 'ABTVL32T1')->count())->toBe(1);
    expect(Category::where('code', 'bab-c10')->count())->toBe(1);
    expect(BuyabansStockLevel::count())->toBe(1);
});

test('a product whose category cannot be resolved still imports, under a placeholder', function () {
    fakeBuyabans([
        'http://backoffice.test/api/forecasting/products*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'product_id' => 200,
                    'sku' => 'ORPHAN-1',
                    'name' => 'Orphaned product',
                    'type' => 'simple',
                    'status' => 1,
                    'price' => '1000.0000',
                    'brand_name' => null,
                    'categories' => [['id' => 9999, 'name' => 'Never synced']],
                ]],
                'meta' => ['count' => 1, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
    ]);

    BuyabansSyncFacade::syncCategories();
    $result = BuyabansSyncFacade::syncProducts();

    expect($result['success'])->toBeTrue();

    $sku = Sku::where('sku', 'ORPHAN-1')->first();

    // A product that sells is worth forecasting whether or not its
    // categorisation came across cleanly — it must not be silently dropped.
    expect($sku)->not->toBeNull();
    expect($sku->product->category->code)->toBe('bab-uncategorised');
});

test('demand for an unknown SKU is kept, with no local SKU linked', function () {
    fakeBuyabans();

    // Demand only — the catalog is deliberately never synced, so the SKU the
    // demand feed reports has no local counterpart.
    BuyabansSyncFacade::syncLocations();
    $result = BuyabansSyncFacade::syncDemand();

    expect($result['success'])->toBeTrue();

    $demand = BuyabansDailyDemand::first();
    expect($demand)->not->toBeNull();
    expect($demand->sku_id)->toBeNull();
    expect($demand->sku_code)->toBe('ABTVL32T1');
});

test('a failed sync is recorded as a failed run rather than throwing', function () {
    fakeBuyabans([
        'http://backoffice.test/api/forecasting/categories*' => Http::response(['error' => 'boom'], 500),
    ]);

    $result = BuyabansSyncFacade::syncCategories();

    expect($result['success'])->toBeFalse();

    $run = BuyabansSyncRun::where('stage', 'categories')->latest('id')->first();
    expect($run->status)->toBe(BuyabansSyncRun::STATUS_FAILED);
    expect($run->message)->toContain('500');
    expect($run->finished_at)->not->toBeNull();
});

test('a sync without credentials fails with a setup message instead of a network error', function () {
    config()->set('services.buyabans.client_id', null);
    config()->set('services.buyabans.client_secret', null);

    $result = BuyabansSyncFacade::syncCategories();

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('not configured');
});

test('the national grain upserts instead of inserting a row per sync', function () {
    config()->set('services.buyabans.grain', 'national');

    fakeBuyabans([
        'http://backoffice.test/api/forecasting/sales-daily*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'date' => '2026-08-01',
                    'sku' => 'ABTVL32T1',
                    'sold_qty' => 9,
                    'revenue' => 413991,
                    'order_count' => 5,
                    'avg_price' => 45999,
                    'discount_amount' => 0,
                    'grain' => 'national',
                ]],
                'meta' => ['grain' => 'national', 'count' => 1, 'next_offset' => null, 'has_more' => false],
            ],
        ]),
    ]);

    BuyabansSyncFacade::syncDemand();
    BuyabansSyncFacade::syncDemand();

    // The national grain reports no location. MySQL treats NULLs in a unique
    // index as distinct, so a null location_code would insert a fresh row every
    // night instead of upserting — hence the empty-string key.
    expect(BuyabansDailyDemand::where('grain', 'national')->count())->toBe(1);
    expect(BuyabansDailyDemand::where('grain', 'national')->value('location_code'))->toBe('');
});

test('the sync page can be triggered from the UI', function () {
    fakeBuyabans();

    $this->actingAs(User::factory()->create())
        ->post(route('buyabans-sync.run'), ['stage' => 'locations'])
        ->assertRedirect();

    expect(Warehouse::where('code', 'DPS45')->exists())->toBeTrue();
});

test('a UI sync wider than 400 days is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('buyabans-sync.run'), ['stage' => 'demand', 'days' => 1100])
        ->assertSessionHasErrors('days');
});

test('the code prefix marks records as back-office sourced', function () {
    expect(BuyabansSyncService::CODE_PREFIX)->toBe('bab-');
});

/**
 * The serving and training paths both switch on `services.ml.demand_source`.
 * These pin that switch, because a mismatch between the two is silent: a model
 * trained on one source and served from the other simply forecasts worse.
 */
test('the demand source defaults to the ledger and rejects an unknown value', function () {
    config()->set('services.ml.demand_source', null);
    expect(MlTrainingDataService::demandSource())->toBe(MlTrainingDataService::SOURCE_LEDGER);

    config()->set('services.ml.demand_source', 'nonsense');
    expect(MlTrainingDataService::demandSource())->toBe(MlTrainingDataService::SOURCE_LEDGER);

    config()->set('services.ml.demand_source', 'buyabans');
    expect(MlTrainingDataService::demandSource())->toBe(MlTrainingDataService::SOURCE_BUYABANS);
});

test('the training export reads synced demand when the source is buyabans', function () {
    fakeBuyabans();
    BuyabansSyncFacade::syncAll();

    config()->set('services.ml.demand_source', 'buyabans');

    $result = MlTrainingDataFacade::export('ml/test_buyabans.csv');

    expect($result['success'])->toBeTrue();
    expect($result['data']['rows'])->toBe(1);

    // Read the path the Service actually writes to. Storage::disk('local')
    // roots at storage/app/private in Laravel 11+, which is not where this
    // writes — MlTrainingDataTest reads it the same way.
    $csv = (string) file_get_contents(storage_path('app/ml/test_buyabans.csv'));
    $lines = array_values(array_filter(explode("\n", $csv)));
    $header = str_getcsv($lines[0]);
    $row = array_combine($header, str_getcsv($lines[1]));

    // The demand and the price are genuinely measured...
    expect($row['sold_qty'])->toBe('3');
    expect((float) $row['selling_price'])->toBe(45999.0);

    // ...the stock covariates are not available on this source, and export as
    // zero rather than as an invented history.
    expect($row['received_qty'])->toBe('0');
    expect($row['stockout_minutes'])->toBe('0');
    expect($row['stockout_flag'])->toBe('0');
});

test('an unknown export source is refused rather than silently falling back', function () {
    expect(MlTrainingDataFacade::export(null, 'nonsense'))
        ->toMatchArray(['success' => false]);
});

test('forecast pairs come from synced demand when the source is buyabans', function () {
    fakeBuyabans();
    BuyabansSyncFacade::syncAll();

    config()->set('services.ml.demand_source', 'buyabans');

    $method = new ReflectionMethod(ForecastRunService::class, 'pairsInScope');

    $pairs = $method->invoke(app(ForecastRunService::class), null);

    $sku = Sku::where('sku', 'ABTVL32T1')->first();
    $warehouse = Warehouse::where('code', 'DPS45')->first();

    expect($pairs)->toBe([['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]]);
});

test('a synced series is served to the ML service from synced demand', function () {
    fakeBuyabans();
    BuyabansSyncFacade::syncAll();

    config()->set('services.ml.demand_source', 'buyabans');

    $sku = Sku::where('sku', 'ABTVL32T1')->first();
    $warehouse = Warehouse::where('code', 'DPS45')->first();

    $series = app(MlServiceClient::class)->buildSeries([
        ['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id],
    ]);

    expect($series[0]['daily_sold_qty'])->toBe([3]);
});

test('a run abandoned by a killed process is closed out when the stage runs again', function () {
    fakeBuyabans();

    $abandoned = BuyabansSyncRun::create([
        'stage' => 'demand',
        'status' => BuyabansSyncRun::STATUS_RUNNING,
        'started_at' => now()->subHour(),
    ]);

    BuyabansSyncFacade::syncDemand();

    // A killed process cannot close its own row, so it would otherwise sit in
    // 'running' forever and quietly make the listing dishonest.
    $abandoned->refresh();
    expect($abandoned->status)->toBe(BuyabansSyncRun::STATUS_FAILED);
    expect($abandoned->message)->toContain('Abandoned');
    expect($abandoned->finished_at)->not->toBeNull();

    expect(BuyabansSyncRun::where('stage', 'demand')->where('status', BuyabansSyncRun::STATUS_SUCCESS)->count())->toBe(1);
});
