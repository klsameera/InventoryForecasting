<?php

use App\Enums\ForecastSource;
use App\Models\BuyabansDailyDemand;
use App\Models\Forecast;
use App\Models\InventoryDailySnapshot;
use App\Models\MlForecastRun;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\ForecastFacade\ForecastFacade;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('forecast.index'));
    $response->assertRedirect(route('login'));
});

test('there are no create, edit, update or delete routes for forecasts', function () {
    expect(Route::has('forecast.create'))->toBeFalse();
    expect(Route::has('forecast.edit'))->toBeFalse();
    expect(Route::has('forecast.store'))->toBeFalse();
    expect(Route::has('forecast.delete'))->toBeFalse();
});

test('authenticated users can view the forecast list', function () {
    $user = User::factory()->create();
    Forecast::factory()->create();

    $response = $this->actingAs($user)->get(route('forecast.index'));

    $response->assertOk();
});

test('scoring accuracy compares a due forecast against actual sales from the daily snapshots', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays(10 - $i),
            'sold_qty' => 5,
        ]);
    }

    $forecast = Forecast::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'forecast_date' => now()->subDay()->toDateString(),
        'horizon_days' => 10,
        'predicted_qty' => 50,
    ]);

    $response = $this->actingAs($user)->post(route('forecast.score-accuracy'));

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'success');
    $forecast->refresh();
    $this->assertDatabaseHas('forecast_accuracy', [
        'forecast_id' => $forecast->id,
    ]);
    expect((float) $forecast->accuracy->actual_qty)->toBe(50.0);
});

test('a forecast whose window has not elapsed yet is not scored', function () {
    $user = User::factory()->create();
    Forecast::factory()->create(['forecast_date' => now()->addDays(10)->toDateString()]);

    $this->actingAs($user)->post(route('forecast.score-accuracy'));

    $this->assertDatabaseCount('forecast_accuracy', 0);
});

test('the overview describes the latest run in plain language', function () {
    config()->set('services.ml.demand_source', 'buyabans');
    config()->set('services.buyabans.grain', 'warehouse');

    $sku = Sku::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $last = now()->subDay()->startOfDay();

    for ($i = 0; $i < 30; $i++) {
        BuyabansDailyDemand::create([
            'demand_date' => $last->copy()->subDays($i)->toDateString(),
            'grain' => 'warehouse',
            'location_code' => 'DPS45',
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'warehouse_id' => $warehouse->id,
            'sold_qty' => 10,
            'revenue' => 1000,
            'avg_price' => 100,
            'discount_amount' => 0,
            'order_count' => 1,
        ]);
    }

    $run = MlForecastRun::factory()->create();

    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 330,
        'lower_qty' => 300,
        'upper_qty' => 360,
        'confidence_score' => 80,
        'forecast_source' => ForecastSource::SkuHistory->value,
    ]);

    $overview = ForecastFacade::overview();
    $summary = $overview['summary'];

    expect($summary['expectedUnits'])->toBe(330.0);
    expect($summary['rangeLow'])->toBe(300.0);
    expect($summary['rangeHigh'])->toBe(360.0);
    expect($summary['previousUnits'])->toBe(300.0);
    expect($summary['changePercent'])->toBe(10.0);

    // A score is a word first and a number second — "80" means nothing to a
    // reader who does not know the scale.
    expect($summary['confidence']['label'])->toBe('High');
    expect($summary['basis'][0]['explanation'])
        ->toBe("Based on this product's own sales history.");
});

test('the forecast band starts where the forecast does', function () {
    config()->set('services.ml.demand_source', 'buyabans');
    config()->set('services.buyabans.grain', 'warehouse');

    $sku = Sku::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $last = now()->subDay()->startOfDay();

    for ($i = 0; $i < 30; $i++) {
        BuyabansDailyDemand::create([
            'demand_date' => $last->copy()->subDays($i)->toDateString(),
            'grain' => 'warehouse',
            'location_code' => 'DPS45',
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'warehouse_id' => $warehouse->id,
            'sold_qty' => 10,
            'revenue' => 1000,
            'avg_price' => 100,
            'discount_amount' => 0,
            'order_count' => 1,
        ]);
    }

    $run = MlForecastRun::factory()->create();
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 330,
        'lower_qty' => 300,
        'upper_qty' => 360,
        'confidence_score' => 80,
        'forecast_source' => ForecastSource::SkuHistory->value,
    ]);

    $chart = ForecastFacade::overview()['chart'];
    $sold = $chart['series'][0]['values'];
    $expected = $chart['series'][1]['values'];

    // The two lines share exactly one point — the last real week — so they join
    // instead of floating apart, and neither claims a value for the other's
    // stretch of time.
    $lastActual = 11;
    expect($sold[$lastActual])->not->toBeNull();
    expect($sold[$lastActual + 1])->toBeNull();
    expect($expected[$lastActual])->toBe($sold[$lastActual]);
    expect($expected[$lastActual - 1])->toBeNull();

    // No range is drawn over weeks that already happened.
    expect($chart['band']['upper'][$lastActual - 1])->toBeNull();
    expect($chart['band']['upper'][$lastActual + 1])->not->toBeNull();
});

test('the listing shows the latest run only, unless asked for all of them', function () {
    $sku = Sku::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $older = MlForecastRun::factory()->create();
    $newer = MlForecastRun::factory()->create();

    foreach ([$older, $newer] as $run) {
        Forecast::factory()->create([
            'forecast_run_id' => $run->id,
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
        ]);
    }

    // Every run re-forecasts the same pairs, so listing them all shows one
    // product once per run it has been through, most of them superseded.
    expect(ForecastFacade::all([])->total())->toBe(1);
    expect(ForecastFacade::all(['all_runs' => true])->total())->toBe(2);
});
