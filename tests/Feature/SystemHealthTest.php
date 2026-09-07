<?php

use App\Models\BuyabansDailyDemand;
use App\Models\Sku;
use App\Models\User;
use Domain\Facades\SystemHealthFacade\SystemHealthFacade;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $this->get(route('system-health.index'))->assertRedirect(route('login'));
});

test('authenticated users can view system health', function () {
    Http::fake(['*' => Http::response(['algorithms' => []], 200)]);

    $this->actingAs(User::factory()->create())
        ->get(route('system-health.index'))
        ->assertOk();
});

test('system health is read-only — it has no write routes', function () {
    expect(Route::has('system-health.store'))->toBeFalse();
    expect(Route::has('system-health.update'))->toBeFalse();
    expect(Route::has('system-health.delete'))->toBeFalse();
});

test('an unreachable dependency is reported, not thrown', function () {
    // The whole point of the page is to survive the thing it is watching being
    // down. A probe that throws takes the health page with it, which is exactly
    // when it is needed most.
    Http::fake(['*' => fn () => throw new ConnectionException('connection refused')]);

    $dependencies = collect(SystemHealthFacade::overview()['dependencies']);

    expect($dependencies)->toHaveCount(3);
    expect($dependencies->firstWhere('name', 'ML service')['reachable'])->toBeFalse();
    // The database is local, so it stays up while the HTTP dependencies do not.
    expect($dependencies->firstWhere('name', 'Database')['reachable'])->toBeTrue();
});

test('the grain reconciliation notices when the grains disagree', function () {
    config()->set('services.buyabans.grain', 'warehouse');
    Http::fake(['*' => Http::response(['algorithms' => []], 200)]);

    $sku = Sku::factory()->create();
    $day = now()->subDay()->toDateString();

    // The same sale recorded at two grains, with different quantities. Grains
    // are different views of identical sales, so this can only be a fault —
    // and it is the one check that catches a forgotten grain filter anywhere
    // upstream.
    foreach ([['warehouse', 10], ['channel', 7]] as [$grain, $qty]) {
        BuyabansDailyDemand::create([
            'demand_date' => $day,
            'grain' => $grain,
            'location_code' => $grain === 'warehouse' ? 'DPS45' : 'default',
            'sku_code' => $sku->sku,
            'sku_id' => $sku->id,
            'warehouse_id' => null,
            'sold_qty' => $qty,
            'revenue' => 100,
            'avg_price' => 10,
            'discount_amount' => 0,
            'order_count' => 1,
        ]);
    }

    expect(SystemHealthFacade::overview()['data']['unitsAgree'])->toBeFalse();
});

test('table sizes are absent rather than zero on a driver that cannot report them', function () {
    Http::fake(['*' => Http::response(['algorithms' => []], 200)]);

    // The suite runs on SQLite, which has no information_schema. Showing zeroes
    // would read as an empty database; absence reads as "not measured".
    expect(SystemHealthFacade::overview()['storage'])->toBeNull();
});
