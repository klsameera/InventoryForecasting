<?php

use App\Models\Forecast;
use App\Models\InventoryDailySnapshot;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
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
