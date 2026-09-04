<?php

use App\Enums\ProductType;
// Aliased: `Attribute` is a reserved class name in PHP 8's attribute syntax.
use App\Models\Attribute as AttributeModel;
use App\Models\Brand;
use App\Models\BuyabansDailyDemand;
use App\Models\BuyabansStockLevel;
use App\Models\BuyabansSyncRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
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
        'http://backoffice.test/api/forecasting/attributes*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'id' => 23,
                    'code' => 'color',
                    'admin_name' => 'Color',
                    'name' => 'Color',
                    'type' => 'select',
                    'options' => [
                        ['id' => 1, 'label' => 'Black', 'sort_order' => 1],
                        ['id' => 2, 'label' => 'White', 'sort_order' => 2],
                    ],
                ]],
                'meta' => ['count' => 1, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
        // A configurable parent, two variant children under it, and one
        // standalone simple product — the shape the real catalog actually has.
        'http://backoffice.test/api/forecasting/products*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [
                    [
                        'product_id' => 100,
                        'sku' => 'ABTVL32T1',
                        'name' => 'Abans 32 Inch TV',
                        'type' => 'simple',
                        'product_type' => 'simple',
                        'parent_id' => null,
                        'status' => 1,
                        'price' => '45999.0000',
                        'brand_name' => 'Abans',
                        'categories' => [['id' => 11, 'name' => 'Refrigerators']],
                        'variant_axes' => [],
                    ],
                    [
                        'product_id' => 200,
                        'sku' => 'PHONE-CONF',
                        'name' => 'Phone X',
                        'type' => 'configurable',
                        'product_type' => 'configurable',
                        'parent_id' => null,
                        'status' => 1,
                        'price' => '0.0000',
                        'brand_name' => 'Abans',
                        'categories' => [['id' => 11, 'name' => 'Refrigerators']],
                        'variant_axes' => [['attribute_id' => 23, 'code' => 'color', 'name' => 'Color']],
                    ],
                    [
                        'product_id' => 201,
                        'sku' => 'PHONE-X-BLACK',
                        'name' => 'Phone X 128GB - Black',
                        'type' => 'simple',
                        'product_type' => 'simple',
                        'parent_id' => 200,
                        'status' => 1,
                        'price' => '99999.0000',
                        'brand_name' => 'Abans',
                        'categories' => [['id' => 11, 'name' => 'Refrigerators']],
                        'variant_axes' => [],
                    ],
                    [
                        'product_id' => 202,
                        'sku' => 'PHONE-X-WHITE',
                        'name' => 'Phone X 128GB - White',
                        'type' => 'simple',
                        'product_type' => 'simple',
                        'parent_id' => 200,
                        'status' => 1,
                        'price' => '99999.0000',
                        'brand_name' => 'Abans',
                        'categories' => [['id' => 11, 'name' => 'Refrigerators']],
                        'variant_axes' => [],
                    ],
                ],
                'meta' => ['count' => 4, 'next_cursor' => null, 'has_more' => false],
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

    // The tree must not multiply either: one parent, two variants, still.
    expect(Product::where('external_id', 200)->count())->toBe(1);
    expect(ProductVariant::count())->toBe(2);
});

test('a product whose category cannot be resolved still imports, under a placeholder', function () {
    fakeBuyabans([
        'http://backoffice.test/api/forecasting/products*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'product_id' => 900,
                    'sku' => 'ORPHAN-1',
                    'name' => 'Orphaned product',
                    'type' => 'simple',
                    'product_type' => 'simple',
                    'parent_id' => null,
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

    $daily = $series[0]['daily_sold_qty'];

    // Dense, not one entry per sale. The feed records only days that sold
    // something; read raw, a pair with one sale in six months looks like a pair
    // that sells 3 units every day. Zero-filling is what makes it a time series.
    expect(count($daily))->toBeGreaterThan(100);
    expect(array_sum($daily))->toBe(3);
    expect(count(array_filter($daily, fn ($q) => $q > 0)))->toBe(1);

    // The window ends on the last day demand was actually synced, so the one
    // real observation is the final entry — days beyond it are unknown, not zero.
    expect(end($daily))->toBe(3);
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

test('a configurable product becomes a parent with variants, not a pile of products', function () {
    fakeBuyabans();

    BuyabansSyncFacade::syncAll();

    // The bug this pins: in Bagisto a variant child is itself type "simple", so
    // a sync that just asks for sellable products flattens the whole catalog
    // into unrelated top-level products and leaves the variants page empty.
    $parent = Product::where('external_id', 200)->first();

    expect($parent)->not->toBeNull();
    expect($parent->product_type)->toBe(ProductType::Configurable);
    expect($parent->variants()->count())->toBe(2);

    // A configurable parent is not sellable — its variants are.
    expect(Sku::where('sku', 'PHONE-CONF')->exists())->toBeFalse();

    $black = Sku::where('sku', 'PHONE-X-BLACK')->first();
    expect($black->product_id)->toBe($parent->id);
    expect($black->product_variant_id)->not->toBeNull();
    expect($black->variant->name)->toBe('Phone X 128GB - Black');

    // The standalone product stays standalone, with no variant.
    expect(Sku::where('sku', 'ABTVL32T1')->first()->product_variant_id)->toBeNull();
});

test('attributes and their values are synced', function () {
    fakeBuyabans();

    BuyabansSyncFacade::syncAttributes();

    $attribute = AttributeModel::where('code', 'color')->first();

    expect($attribute)->not->toBeNull();
    expect($attribute->forecast_relevant)->toBeTrue();
    expect($attribute->values()->pluck('value')->all())->toBe(['Black', 'White']);
});

test('a product left owning nothing by a re-shaped catalog is removed', function () {
    fakeBuyabans();

    // Stand in for the earlier flat import: a synced product with no SKUs and
    // no variants, which describes nothing at all.
    $stale = Product::factory()->create(['external_id' => 999999]);

    BuyabansSyncFacade::syncAll();

    expect(Product::withTrashed()->find($stale->id))->toBeNull();
});

test('a second sync creates and destroys nothing', function () {
    fakeBuyabans();

    BuyabansSyncFacade::syncAll();
    $first = BuyabansSyncRun::where('stage', 'products')->latest('id')->first();

    BuyabansSyncFacade::syncProducts();
    $second = BuyabansSyncRun::where('stage', 'products')->latest('id')->first();

    // Idempotence is not a nicety here. Before this was pinned, 147 products
    // were created and deleted on every run, and the run summary reported the
    // deletions as if they were progress.
    expect($first->summary['removed_flattened'])->toBe(0);
    expect($second->summary['removed_flattened'])->toBe(0);
    expect($second->summary['childless_parents'])->toBe(0);
});

test('two products claiming the same SKU do not fight over it every run', function () {
    fakeBuyabans([
        'http://backoffice.test/api/forecasting/products*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [
                    [
                        'product_id' => 300,
                        'sku' => 'SHARED-SKU',
                        'name' => 'First claimant',
                        'type' => 'simple',
                        'product_type' => 'simple',
                        'parent_id' => null,
                        'status' => 1,
                        'price' => '100.0000',
                        'brand_name' => null,
                        'categories' => [],
                        'variant_axes' => [],
                    ],
                    [
                        'product_id' => 301,
                        'sku' => 'SHARED-SKU',
                        'name' => 'Second claimant',
                        'type' => 'simple',
                        'product_type' => 'simple',
                        'parent_id' => null,
                        'status' => 1,
                        'price' => '200.0000',
                        'brand_name' => null,
                        'categories' => [],
                        'variant_axes' => [],
                    ],
                ],
                'meta' => ['count' => 2, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
    ]);

    BuyabansSyncFacade::syncProducts();
    $run = BuyabansSyncRun::where('stage', 'products')->latest('id')->first();

    // `skus.sku` is unique — it is the key demand resolves through — so only one
    // product can own a code. The loser is reported, not silently created and
    // swept away again on the next pass.
    expect($run->summary['duplicate_skus'])->toBe(1);
    expect(Sku::where('sku', 'SHARED-SKU')->count())->toBe(1);
    expect(Product::where('external_id', 300)->exists())->toBeTrue();
    expect(Product::where('external_id', 301)->exists())->toBeFalse();
    expect($run->summary['removed_flattened'])->toBe(0);
});

test('a configurable product with no children is never created', function () {
    fakeBuyabans([
        'http://backoffice.test/api/forecasting/products*' => Http::response([
            'status' => true,
            'message' => 'ok',
            'data' => [
                'items' => [[
                    'product_id' => 400,
                    'sku' => 'EMPTY-CONF',
                    'name' => 'Configurable with no variants',
                    'type' => 'configurable',
                    'product_type' => 'configurable',
                    'parent_id' => null,
                    'status' => 1,
                    'price' => '0.0000',
                    'brand_name' => null,
                    'categories' => [],
                    'variant_axes' => [],
                ]],
                'meta' => ['count' => 1, 'next_cursor' => null, 'has_more' => false],
            ],
        ]),
    ]);

    BuyabansSyncFacade::syncProducts();
    $run = BuyabansSyncRun::where('stage', 'products')->latest('id')->first();

    // Parents are created lazily, when a child first needs one. Writing a
    // childless parent only for the orphan cleanup to delete it again is churn,
    // not a sync.
    expect(Product::where('external_id', 400)->exists())->toBeFalse();
    expect($run->summary['childless_parents'])->toBe(1);
    expect($run->summary['removed_flattened'])->toBe(0);
});

test('the served series reads only the configured grain', function () {
    fakeBuyabans();
    BuyabansSyncFacade::syncAll();

    $sku = Sku::where('sku', 'ABTVL32T1')->first();
    $warehouse = Warehouse::where('code', 'DPS45')->first();

    // The same sale, also recorded at the channel grain — as it is once more
    // than one grain has been synced. Serving must not add them together.
    BuyabansDailyDemand::create([
        'demand_date' => '2026-08-01',
        'grain' => 'channel',
        'location_code' => 'default',
        'sku_code' => $sku->sku,
        'sku_id' => $sku->id,
        'warehouse_id' => $warehouse->id,
        'sold_qty' => 3,
        'revenue' => 137997,
        'avg_price' => 45999,
        'discount_amount' => 0,
        'order_count' => 2,
    ]);

    config()->set('services.ml.demand_source', 'buyabans');

    $daily = app(MlServiceClient::class)->buildSeries([
        ['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id],
    ])[0]['daily_sold_qty'];

    expect(array_sum($daily))->toBe(3);
});
