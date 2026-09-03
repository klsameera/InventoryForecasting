<?php

use App\Enums\SalesOrderStatus;
use App\Models\Inventory;
use App\Models\SalesOrder;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('sales-return.index'));
    $response->assertRedirect(route('login'));
});

test('there are no edit, update or delete routes for sales returns', function () {
    expect(Route::has('sales-return.edit'))->toBeFalse();
    expect(Route::has('sales-return.update'))->toBeFalse();
    expect(Route::has('sales-return.delete'))->toBeFalse();
});

test('a sellable return puts stock back at the sale cost', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 5,
        'available_qty' => 5,
        'average_cost' => 15,
    ]);
    $order = SalesOrder::factory()->create([
        'warehouse_id' => $warehouse->id,
        'status' => SalesOrderStatus::Confirmed,
    ]);
    $item = $order->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 10,
        'unit_price' => 25,
        'discount' => 0,
        'net_amount' => 250,
        'cost' => 15,
    ]);

    $response = $this->actingAs($user)->post(route('sales-return.store'), [
        'sales_order_id' => $order->id,
        'return_date' => now()->toDateString(),
        'items' => [
            ['sales_order_item_id' => $item->id, 'quantity' => 3, 'condition' => 'sellable'],
        ],
    ]);

    $response->assertRedirect(route('sales-return.index'));
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 8,
    ]);
    $this->assertDatabaseHas('stock_movements', [
        'sku_id' => $sku->id,
        'movement_type' => 'SALE_RETURN',
        'quantity' => 3,
    ]);
});

test('a damaged return nets zero on-hand change but records both ledger entries', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 5,
        'available_qty' => 5,
    ]);
    $order = SalesOrder::factory()->create([
        'warehouse_id' => $warehouse->id,
        'status' => SalesOrderStatus::Confirmed,
    ]);
    $item = $order->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 10,
        'unit_price' => 25,
        'discount' => 0,
        'net_amount' => 250,
        'cost' => 15,
    ]);

    $this->actingAs($user)->post(route('sales-return.store'), [
        'sales_order_id' => $order->id,
        'return_date' => now()->toDateString(),
        'items' => [
            ['sales_order_item_id' => $item->id, 'quantity' => 2, 'condition' => 'damaged'],
        ],
    ]);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 5,
    ]);
    $this->assertDatabaseHas('stock_movements', ['sku_id' => $sku->id, 'movement_type' => 'SALE_RETURN', 'quantity' => 2]);
    $this->assertDatabaseHas('stock_movements', ['sku_id' => $sku->id, 'movement_type' => 'DAMAGE', 'quantity' => 2]);
});

test('a return cannot exceed the quantity sold on that line', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $order = SalesOrder::factory()->create([
        'warehouse_id' => $warehouse->id,
        'status' => SalesOrderStatus::Confirmed,
    ]);
    $item = $order->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 5,
        'unit_price' => 25,
        'discount' => 0,
        'net_amount' => 125,
        'cost' => 15,
    ]);

    $response = $this->actingAs($user)->post(route('sales-return.store'), [
        'sales_order_id' => $order->id,
        'return_date' => now()->toDateString(),
        'items' => [
            ['sales_order_item_id' => $item->id, 'quantity' => 20, 'condition' => 'sellable'],
        ],
    ]);

    $response->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseCount('sales_returns', 0);
});
