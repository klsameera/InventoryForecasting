<?php

use App\Enums\ForecastRunStatus;
use App\Enums\MovementType;
use App\Jobs\RunDemandForecast;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\ForecastAccuracy;
use App\Models\Inventory;
use App\Models\InventoryDailySnapshot;
use App\Models\MlForecastRun;
use App\Models\MlModelVersion;
use App\Models\Product;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\ForecastRunFacade\ForecastRunFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('forecast-run.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the forecast run list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('forecast-run.index'));

    $response->assertOk();
});

test('queueing a forecast run creates it in Queued status and dispatches the job', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('forecast-run.store'), [
        'horizon_days' => 30,
    ]);

    $response->assertRedirect(route('forecast-run.index'));
    $run = MlForecastRun::firstOrFail();
    expect($run->status)->toBe(ForecastRunStatus::Queued);
    expect($run->horizon_days)->toBe(30);
    Queue::assertPushed(RunDemandForecast::class);
});

test('an invalid horizon is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('forecast-run.store'), [
        'horizon_days' => 45,
    ]);

    $response->assertSessionHasErrors('horizon_days');
});

test('processing a run calls the ML service and persists forecasts', function () {
    Http::fake([
        '*/forecast/run' => Http::response([
            'status' => 'completed',
            'results' => [
                [
                    'warehouse_id' => 1,
                    'sku_id' => 1,
                    'predicted_qty' => 150.0,
                    'lower_qty' => 140.0,
                    'upper_qty' => 160.0,
                    'confidence_score' => 90,
                    'forecast_source' => 'SKU_HISTORY',
                ],
            ],
        ], 200),
    ]);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    // Established maturity (>=30 days of sale history, no decline data seeded)
    // keeps this pair on the plain SKU-history path — see the three Phase 6
    // tests below for the Cold-start/Early fallback paths this would
    // otherwise take.
    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(40),
    ]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);
    $run = $result['data'];

    ForecastRunFacade::processRun($run->id);

    $run->refresh();
    expect($run->status)->toBe(ForecastRunStatus::Completed);
    expect($run->model_version_id)->not->toBeNull();
    $this->assertDatabaseHas('forecasts', [
        'forecast_run_id' => $run->id,
        'predicted_qty' => 150.0,
        'confidence_score' => 90,
        'forecast_source' => 'SKU_HISTORY',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/forecast/run')
            && $request['horizon_days'] === 30;
    });
});

test('a cold-start sku with no peer history gets a cold-start forecast', function () {
    Http::fake([
        // Python has no opinion on why a series was chosen and always
        // defaults to SKU_HISTORY — Laravel's own classification must win.
        '*/forecast/run' => Http::response([
            'status' => 'completed',
            'results' => [[
                'warehouse_id' => 1,
                'sku_id' => 1,
                'predicted_qty' => 0.0,
                'lower_qty' => 0.0,
                'upper_qty' => 0.0,
                'confidence_score' => 10,
                'forecast_source' => 'SKU_HISTORY',
            ]],
        ], 200),
    ]);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);
    ForecastRunFacade::processRun($result['data']->id);

    $this->assertDatabaseHas('forecasts', [
        'forecast_run_id' => $result['data']->id,
        'forecast_source' => 'COLD_START',
    ]);

    Http::assertSent(function ($request) {
        $series = $request->data()['series'][0]['daily_sold_qty'];

        return array_sum($series) === 0.0;
    });
});

test('a cold-start sku with category peer history gets a category-fallback forecast', function () {
    Http::fake(['*/forecast/run' => Http::response([
        'status' => 'completed',
        'results' => [['warehouse_id' => 1, 'sku_id' => 1, 'predicted_qty' => 5.0, 'lower_qty' => 2.0, 'upper_qty' => 8.0, 'confidence_score' => 40, 'forecast_source' => 'SKU_HISTORY']],
    ], 200)]);

    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();

    $coldProduct = Product::factory()->create(['category_id' => $category->id]);
    $coldSku = Sku::factory()->create(['product_id' => $coldProduct->id]);
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $coldSku->id]);

    $peerProduct = Product::factory()->create(['category_id' => $category->id]);
    $peerSku = Sku::factory()->create(['product_id' => $peerProduct->id]);
    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $peerSku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 20,
    ]);

    $result = ForecastRunFacade::store(['horizon_days' => 30, 'warehouse_ids' => [$warehouse->id]]);
    ForecastRunFacade::processRun($result['data']->id);

    $this->assertDatabaseHas('forecasts', [
        'forecast_run_id' => $result['data']->id,
        'forecast_source' => 'CATEGORY',
    ]);
});

test('an early-maturity sku gets a hybrid forecast blending its own history with the fallback', function () {
    Http::fake(['*/forecast/run' => Http::response([
        'status' => 'completed',
        'results' => [['warehouse_id' => 1, 'sku_id' => 1, 'predicted_qty' => 12.0, 'lower_qty' => 5.0, 'upper_qty' => 20.0, 'confidence_score' => 55, 'forecast_source' => 'SKU_HISTORY']],
    ], 200)]);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(5),
    ]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);
    ForecastRunFacade::processRun($result['data']->id);

    $this->assertDatabaseHas('forecasts', [
        'forecast_run_id' => $result['data']->id,
        'forecast_source' => 'HYBRID',
    ]);
});

test('a failed ML service call marks the run as Failed with an error message', function () {
    Http::fake([
        '*/forecast/run' => Http::response('service unavailable', 503),
    ]);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);
    $run = $result['data'];

    ForecastRunFacade::processRun($run->id);

    $run->refresh();
    expect($run->status)->toBe(ForecastRunStatus::Failed);
    expect($run->error_message)->not->toBeNull();
    $this->assertDatabaseCount('forecasts', 0);
});

test('a new pair with no accuracy history yet is sent to the ML service with the default ewma algorithm', function () {
    Http::fake([
        '*/forecast/run' => Http::response([
            'status' => 'completed',
            'results' => [[
                'warehouse_id' => 1, 'sku_id' => 1, 'predicted_qty' => 100.0,
                'lower_qty' => 90.0, 'upper_qty' => 110.0, 'confidence_score' => 80,
                'forecast_source' => 'SKU_HISTORY', 'algorithm' => 'ewma',
            ]],
        ], 200),
    ]);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);
    ForecastRunFacade::processRun($result['data']->id);

    Http::assertSent(fn ($request) => $request->data()['series'][0]['algorithm'] === 'ewma');

    $forecast = Forecast::where('sku_id', $sku->id)->firstOrFail();
    expect($forecast->modelVersion->name)->toBe('baseline-moving-average');
});

test('a sku with better historical seasonal-naive accuracy is forecasted with that algorithm instead', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $ewmaVersion = MlModelVersion::factory()->create(['name' => 'baseline-moving-average', 'version' => 'v1']);
    $seasonalVersion = MlModelVersion::factory()->create(['name' => 'seasonal-naive', 'version' => 'v1']);

    $ewmaForecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $ewmaVersion->id]);
    ForecastAccuracy::factory()->create(['forecast_id' => $ewmaForecast->id, 'percentage_error' => 40.0]);

    $seasonalForecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $seasonalVersion->id]);
    ForecastAccuracy::factory()->create(['forecast_id' => $seasonalForecast->id, 'percentage_error' => 5.0]);

    Http::fake([
        '*/forecast/run' => Http::response([
            'status' => 'completed',
            'results' => [[
                'warehouse_id' => $warehouse->id, 'sku_id' => $sku->id, 'predicted_qty' => 42.0,
                'lower_qty' => 30.0, 'upper_qty' => 50.0, 'confidence_score' => 70,
                'forecast_source' => 'SKU_HISTORY', 'algorithm' => 'seasonal_naive',
            ]],
        ], 200),
    ]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);
    ForecastRunFacade::processRun($result['data']->id);

    Http::assertSent(fn ($request) => $request->data()['series'][0]['algorithm'] === 'seasonal_naive');

    $newForecast = Forecast::where('sku_id', $sku->id)->where('predicted_qty', 42.0)->firstOrFail();
    expect($newForecast->modelVersion->name)->toBe('seasonal-naive');
});

test('a run scoped to no warehouses with no inventory completes with zero forecasts', function () {
    $result = ForecastRunFacade::store(['horizon_days' => 7]);
    $run = $result['data'];

    ForecastRunFacade::processRun($run->id);

    $run->refresh();
    expect($run->status)->toBe(ForecastRunStatus::Completed);
    expect($run->forecasts()->count())->toBe(0);
});

test('a refused series produces no forecast at all', function () {
    // The model declines; nothing is substituted in its place. Before this,
    // the service answered a refusal with EWMA under its own name — accurate
    // bookkeeping, but it meant asking for a trained model and receiving
    // arithmetic, with the refusal hidden behind a plausible number.
    Http::fake([
        '*/forecast/run' => Http::response([
            'status' => 'partial',
            'results' => [],
            'refusals' => [[
                'warehouse_id' => 1,
                'sku_id' => 1,
                'algorithm' => 'tft',
                'reason' => 'sku 1 was not in the training data',
            ]],
        ], 200),
    ]);

    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    // No explicit processRun() here: store() dispatches RunDemandForecast and
    // the suite runs on the sync queue, so the batch has already executed by
    // the time store() returns. Calling it again runs the whole thing twice.
    $result = ForecastRunFacade::store(['horizon_days' => 30]);

    $run = $result['data']->fresh();

    expect(Forecast::where('forecast_run_id', $run->id)->count())->toBe(0);
    // Nothing served at all is a failure — the operator asked for an algorithm
    // and got none of it — and the reason has to survive to the page.
    expect($run->status)->toBe(ForecastRunStatus::Failed);
    expect($run->error_message)->toContain('tft');
    expect($run->error_message)->toContain('not in the training data');
});

test('a partial refusal keeps the served forecasts and still reports the refusal', function () {
    // One unservable series must not cost the rest their answers: a real run
    // covers thousands of pairs and a handful may be too new for a trained
    // model. The run completes, and the message is what surfaces the hole.
    $warehouse = Warehouse::factory()->create();
    $served = Sku::factory()->create();
    $refused = Sku::factory()->create();

    foreach ([$served, $refused] as $sku) {
        Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);
    }

    Http::fake([
        '*/forecast/run' => Http::response([
            'status' => 'partial',
            'results' => [[
                'warehouse_id' => $warehouse->id,
                'sku_id' => $served->id,
                'predicted_qty' => 30.0,
                'lower_qty' => 20.0,
                'upper_qty' => 40.0,
                'confidence_score' => 55,
                'forecast_source' => 'SKU_HISTORY',
                'algorithm' => 'tft',
            ]],
            'refusals' => [[
                'warehouse_id' => $warehouse->id,
                'sku_id' => $refused->id,
                'algorithm' => 'tft',
                'reason' => 'needs at least 45 days of history',
            ]],
        ], 200),
    ]);

    $result = ForecastRunFacade::store(['horizon_days' => 30]);

    $run = $result['data']->fresh();

    expect($run->status)->toBe(ForecastRunStatus::Completed);
    expect(Forecast::where('forecast_run_id', $run->id)->pluck('sku_id')->all())->toBe([$served->id]);
    expect($run->error_message)->toContain('45 days');
});

test('retry starts a fresh run with the original settings', function () {
    Http::fake(['*/forecast/run' => Http::response(['status' => 'completed', 'results' => []], 200)]);

    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $first = ForecastRunFacade::store([
        'horizon_days' => 60,
        'warehouse_ids' => [$warehouse->id],
    ])['data'];

    $response = $this->actingAs($user)->post(route('forecast-run.retry', $first->id));

    $response->assertRedirect();

    // A new record beside the first, not an edit of it: a run is a
    // point-in-time record of what was asked and what happened, including a
    // refusal, and retrying must not erase the attempt that failed.
    $latest = MlForecastRun::query()->orderByDesc('id')->first();

    expect($latest->id)->not->toBe($first->id);
    expect($latest->horizon_days)->toBe(60);
    expect($latest->warehouse_ids)->toBe([$warehouse->id]);
    expect(MlForecastRun::find($first->id))->not->toBeNull();
});
