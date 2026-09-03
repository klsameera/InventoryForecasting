<?php

use App\Models\Inventory;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('stock-movement.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the movement ledger', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('stock-movement.index'));

    $response->assertOk();
});

test('there are no edit, update or delete routes for stock movements', function () {
    expect(Route::has('stock-movement.edit'))->toBeFalse();
    expect(Route::has('stock-movement.update'))->toBeFalse();
    expect(Route::has('stock-movement.delete'))->toBeFalse();
});

test('an opening stock movement creates the inventory balance', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 100,
        'unit_cost' => 25,
    ]);

    $response->assertRedirect(route('stock-movement.index'));
    $this->assertDatabaseHas('stock_movements', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 100,
    ]);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'average_cost' => 25,
    ]);
});

test('a second inbound movement rolls the average cost forward', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 100,
        'unit_cost' => 20,
    ]);

    $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_IN',
        'quantity' => 100,
        'unit_cost' => 30,
    ]);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 200,
        'average_cost' => 25,
    ]);
});

test('an outbound movement exceeding on-hand stock is rejected without mutating the balance', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 10,
        'available_qty' => 10,
    ]);

    $response = $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_OUT',
        'quantity' => 50,
    ]);

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseCount('stock_movements', 0);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 10,
    ]);
});

test('only manual-entry movement types can be recorded through the form', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'SALE',
        'quantity' => 1,
    ]);

    $response->assertSessionHasErrors('movement_type');
});
