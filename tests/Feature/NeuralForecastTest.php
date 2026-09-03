<?php

/**
 * The Laravel half of serving a trained model.
 *
 * The Python half is covered by `ml-service/tests/test_neural.py`; nothing here
 * loads a checkpoint. What is pinned here is the wiring around it — which pairs
 * get the covariate block, which never do, and (the one that actually protects
 * accuracy history) that a forecast row records the algorithm the service
 * really ran rather than the one it was asked for.
 */

use App\Enums\MovementType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\Inventory;
use App\Models\InventoryDailySnapshot;
use App\Models\Product;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Domain\Facades\ForecastRunFacade\ForecastRunFacade;
use Domain\Facades\ModelSelectionFacade\ModelSelectionFacade;
use Domain\Services\MlServiceClient\MlServiceClient;
use Illuminate\Support\Facades\Http;

/**
 * A pair with enough continuous history to classify as Established, which is
 * the only maturity a trained model is ever offered.
 *
 * @return array{0: Warehouse, 1: Sku}
 */
function establishedPair(int $days = 200): array
{
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $sku = Sku::factory()->create(['product_id' => $product->id, 'selling_price' => 1500]);

    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    // Maturity is classified from the first Sale movement, not from snapshots —
    // snapshots alone leave the pair Cold-start and it never reaches the branch
    // this file is about.
    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays($days),
    ]);

    for ($day = $days; $day >= 1; $day--) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($day),
            'sold_qty' => 4,
            'available_qty' => 60,
            'received_qty' => 0,
            'stockout_minutes' => 0,
        ]);
    }

    return [$warehouse, $sku];
}

function fakeMlResponse(array $overrides = []): void
{
    Http::fake(['*/forecast/run' => Http::response([
        'status' => 'completed',
        'results' => [array_merge([
            'warehouse_id' => 1,
            'sku_id' => 1,
            'predicted_qty' => 120.0,
            'lower_qty' => 100.0,
            'upper_qty' => 140.0,
            'confidence_score' => 70,
            'forecast_source' => 'SKU_HISTORY',
            'algorithm' => 'tft',
            'fallback_reason' => null,
        ], $overrides)],
    ], 200)]);
}

test('the preferred algorithm defaults to the ewma baseline', function () {
    config(['services.ml.default_algorithm' => 'ewma']);

    expect(ModelSelectionFacade::preferredAlgorithm())->toBe('ewma');
});

test('the preferred algorithm can be configured to a trained model', function () {
    config(['services.ml.default_algorithm' => 'tft']);

    expect(ModelSelectionFacade::preferredAlgorithm())->toBe('tft');
});

test('an unrecognised configured algorithm falls back to the baseline instead of being sent', function () {
    // A typo in ML_DEFAULT_ALGORITHM must not reach the wire, where it would
    // be a 422 from Pydantic and fail the whole run rather than one series.
    config(['services.ml.default_algorithm' => 'prophet']);

    expect(ModelSelectionFacade::preferredAlgorithm())->toBe('ewma');
});

test('an established sku on a neural algorithm is sent the full covariate block', function () {
    config(['services.ml.default_algorithm' => 'tft']);
    fakeMlResponse();

    [$warehouse, $sku] = establishedPair();

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    Http::assertSent(function ($request) use ($sku) {
        $series = $request->data()['series'][0];

        expect($series['algorithm'])->toBe('tft');
        expect($series)->toHaveKey('features');

        $features = $series['features'];

        // Every field the model was trained on has to be present. A partially
        // filled block is not a slightly worse forecast — it tells the model
        // this SKU is free, uncategorised and never out of stock.
        expect($features)->toHaveKeys([
            'series_start_date',
            'category_id',
            'brand_id',
            'selling_price',
            'available_qty',
            'received_qty',
            'stockout_minutes',
            'on_promotion',
            'promotion_discount',
            'future_on_promotion',
            'future_promotion_discount',
        ]);

        // Past-observed covariates are parallel to the demand series.
        expect(count($features['available_qty']))->toBe(count($series['daily_sold_qty']));
        expect(count($features['stockout_minutes']))->toBe(count($series['daily_sold_qty']));

        // Known-future covariates must cover the decoder's full fixed length,
        // not just the horizon asked for.
        expect(count($features['future_on_promotion']))
            ->toBeGreaterThanOrEqual(MlServiceClient::NEURAL_DECODER_DAYS);

        expect($features['selling_price'])->toBe(1500.0);
        expect($features['category_id'])->toBe($sku->product->category_id);

        return true;
    });
});

test('a baseline algorithm is never sent a covariate block', function () {
    config(['services.ml.default_algorithm' => 'ewma']);
    fakeMlResponse(['algorithm' => 'ewma']);

    [$warehouse] = establishedPair();

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    Http::assertSent(function ($request) {
        $series = $request->data()['series'][0];

        expect($series['algorithm'])->toBe('ewma');

        return ! array_key_exists('features', $series);
    });
});

test('a cold-start sku is forced onto a baseline even when a neural model is preferred', function () {
    // Its series has been replaced with peer demand. Pairing that with this
    // SKU's own embedding, price and category would describe one product using
    // another product's sales.
    config(['services.ml.default_algorithm' => 'tft']);
    fakeMlResponse(['algorithm' => 'ewma']);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    Http::assertSent(function ($request) {
        $series = $request->data()['series'][0];

        return $series['algorithm'] === 'ewma' && ! array_key_exists('features', $series);
    });
});

test('a forecast records the algorithm the service actually ran, not the one requested', function () {
    // The guarantee that keeps ModelSelectionService honest: if a fallback were
    // filed under the neural model's name, that model would accumulate accuracy
    // history for forecasts it never produced and could win a comparison it
    // never took part in.
    config(['services.ml.default_algorithm' => 'tft']);
    fakeMlResponse([
        'algorithm' => 'ewma',
        'fallback_reason' => 'tft: series 1-1 was not in the training data (cold start)',
    ]);

    [$warehouse] = establishedPair();

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    $forecast = Forecast::with('modelVersion')->firstOrFail();

    expect($forecast->modelVersion->name)->toBe('baseline-moving-average');
});

test('a forecast records the neural model when the service really ran it', function () {
    config(['services.ml.default_algorithm' => 'tft']);
    fakeMlResponse(['algorithm' => 'tft']);

    [$warehouse] = establishedPair();

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    $forecast = Forecast::with('modelVersion')->firstOrFail();

    expect($forecast->modelVersion->name)->toBe('temporal-fusion-transformer');
    expect($forecast->modelVersion->model_type)->toBe('tft');
});

test('a response without an algorithm field still records the requested one', function () {
    // Backwards compatibility with a service predating the echo, so a partial
    // deployment does not silently file every forecast under the default.
    config(['services.ml.default_algorithm' => 'tft']);

    Http::fake(['*/forecast/run' => Http::response([
        'status' => 'completed',
        'results' => [[
            'warehouse_id' => 1,
            'sku_id' => 1,
            'predicted_qty' => 120.0,
            'lower_qty' => 100.0,
            'upper_qty' => 140.0,
            'confidence_score' => 70,
            'forecast_source' => 'SKU_HISTORY',
        ]],
    ], 200)]);

    [$warehouse] = establishedPair();

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    $forecast = Forecast::with('modelVersion')->firstOrFail();

    expect($forecast->modelVersion->model_type)->toBe('tft');
});
