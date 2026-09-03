<?php

use App\Models\Inventory;
use App\Models\InventoryDailySnapshot;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\StockMovementFacade\StockMovementFacade;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('inventory-daily-snapshot.index'));
    $response->assertRedirect(route('login'));
});

test('there are no create, edit, update or delete routes for daily snapshots', function () {
    expect(Route::has('inventory-daily-snapshot.create'))->toBeFalse();
    expect(Route::has('inventory-daily-snapshot.edit'))->toBeFalse();
    expect(Route::has('inventory-daily-snapshot.update'))->toBeFalse();
    expect(Route::has('inventory-daily-snapshot.delete'))->toBeFalse();
});

test('authenticated users can view the snapshot history with no data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('inventory-daily-snapshot.index'));

    $response->assertOk();
});

test('capturing a day computes opening, closing, demand and stockout minutes correctly', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id, 'on_hand_qty' => 0, 'available_qty' => 0, 'average_cost' => 0]);

    $yesterday = now()->subDay()->startOfDay();

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 10,
        'unit_cost' => 20,
        'occurred_at' => $yesterday->copy()->addHours(8),
    ]);
    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'SALE',
        'quantity' => 10,
        'occurred_at' => $yesterday->copy()->addHours(10),
    ]);
    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'PURCHASE_RECEIPT',
        'quantity' => 5,
        'unit_cost' => 22,
        'occurred_at' => $yesterday->copy()->addHours(14),
    ]);

    $response = $this->actingAs($user)->post(route('inventory-daily-snapshot.capture'), [
        'date' => $yesterday->toDateString(),
    ]);

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'success');

    $snapshot = InventoryDailySnapshot::where('warehouse_id', $warehouse->id)->where('sku_id', $sku->id)->firstOrFail();

    expect($snapshot->opening_qty)->toBe(0);
    expect($snapshot->received_qty)->toBe(5);
    expect($snapshot->sold_qty)->toBe(10);
    expect($snapshot->adjustment_qty)->toBe(10);
    expect($snapshot->closing_qty)->toBe(5);
    expect($snapshot->stockout_minutes)->toBe(720);
    expect($snapshot->stockout_flag)->toBeTrue();
    expect((float) $snapshot->inventory_value)->toBe(110.0);
});

test('re-capturing the same day is idempotent', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);
    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 20,
        'unit_cost' => 10,
        'occurred_at' => now()->subDay(),
    ]);

    $this->actingAs($user)->post(route('inventory-daily-snapshot.capture'), ['date' => now()->subDay()->toDateString()]);
    $second = $this->actingAs($user)->post(route('inventory-daily-snapshot.capture'), ['date' => now()->subDay()->toDateString()]);

    $second->assertInertiaFlash('toast.type', 'success');
    expect(InventoryDailySnapshot::count())->toBe(1);
});

test('a prior day\'s closing quantity becomes the next day\'s opening quantity', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);
    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 20,
        'unit_cost' => 10,
        'occurred_at' => now()->subDays(2),
    ]);

    $this->actingAs($user)->post(route('inventory-daily-snapshot.capture'), ['date' => now()->subDays(2)->toDateString()]);
    $this->actingAs($user)->post(route('inventory-daily-snapshot.capture'), ['date' => now()->subDay()->toDateString()]);

    $today = InventoryDailySnapshot::whereDate('snapshot_date', now()->subDay()->toDateString())->firstOrFail();

    expect($today->opening_qty)->toBe(20);
});

test('filtering by stockout only returns just the stocked-out rows', function () {
    $user = User::factory()->create();
    InventoryDailySnapshot::factory()->create(['stockout_flag' => true, 'stockout_minutes' => 60]);
    InventoryDailySnapshot::factory()->create(['stockout_flag' => false, 'stockout_minutes' => null]);

    $response = $this->actingAs($user)->get(route('inventory-daily-snapshot.index', ['stockout_only' => '1']));

    $response->assertOk();
});
