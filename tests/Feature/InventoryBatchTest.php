<?php

use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('inventory-batch.index'));
    $response->assertRedirect(route('login'));
});

test('there are no create, edit, update or delete routes for inventory batches', function () {
    expect(Route::has('inventory-batch.create'))->toBeFalse();
    expect(Route::has('inventory-batch.edit'))->toBeFalse();
    expect(Route::has('inventory-batch.update'))->toBeFalse();
    expect(Route::has('inventory-batch.delete'))->toBeFalse();
});

test('stock movements open and consume FIFO batches oldest first', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 10,
        'unit_cost' => 10,
    ]);

    $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_IN',
        'quantity' => 10,
        'unit_cost' => 20,
    ]);

    $this->assertDatabaseHas('inventory_batches', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'unit_cost' => 10,
        'remaining_qty' => 10,
    ]);
    $this->assertDatabaseHas('inventory_batches', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'unit_cost' => 20,
        'remaining_qty' => 10,
    ]);

    $this->actingAs($user)->post(route('stock-movement.store'), [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_OUT',
        'quantity' => 15,
    ]);

    $this->assertDatabaseHas('inventory_batches', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'unit_cost' => 10,
        'remaining_qty' => 0,
    ]);
    $this->assertDatabaseHas('inventory_batches', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'unit_cost' => 20,
        'remaining_qty' => 5,
    ]);

    $response = $this->actingAs($user)->get(route('inventory-batch.index'));
    $response->assertOk();
});
