<?php

use App\Enums\SalesOrderStatus;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\SalesOrder;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('sales-order.index'));
    $response->assertRedirect(route('login'));
});

test('a sales order can be created in draft without touching stock', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('sales-order.store'), [
        'warehouse_id' => $warehouse->id,
        'customer_name' => 'Jane Doe',
        'order_date' => now()->toDateString(),
        'items' => [
            ['sku_id' => $sku->id, 'quantity' => 4, 'unit_price' => 25],
        ],
    ]);

    $order = SalesOrder::firstOrFail();
    $response->assertRedirect(route('sales-order.edit', $order));
    expect($order->status)->toBe(SalesOrderStatus::Draft);
    expect((float) $order->total)->toBe(100.0);
    $this->assertDatabaseCount('stock_movements', 0);
});

test('confirming a sales order deducts stock and captures the FIFO cost on the line', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 50,
        'available_qty' => 50,
        'average_cost' => 15,
    ]);
    InventoryBatch::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'received_qty' => 50,
        'remaining_qty' => 50,
        'unit_cost' => 15,
    ]);

    $order = SalesOrder::factory()->create(['warehouse_id' => $warehouse->id]);
    $item = $order->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 10,
        'unit_price' => 25,
        'discount' => 0,
        'net_amount' => 250,
    ]);

    $response = $this->actingAs($user)->post(route('sales-order.confirm', $order));

    $response->assertRedirect();
    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 40,
    ]);
    $this->assertDatabaseHas('sales_order_items', ['id' => $item->id, 'cost' => 15]);
    $this->assertDatabaseHas('stock_movements', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'SALE',
        'quantity' => 10,
        'reference_type' => 'sales_order',
    ]);
});

test('confirming a sales order that exceeds on-hand stock is rejected', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 2,
        'available_qty' => 2,
    ]);
    $order = SalesOrder::factory()->create(['warehouse_id' => $warehouse->id]);
    $order->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 10,
        'unit_price' => 25,
        'discount' => 0,
        'net_amount' => 250,
    ]);

    $response = $this->actingAs($user)->post(route('sales-order.confirm', $order));

    $response->assertInertiaFlash('toast.type', 'error');
    expect($order->fresh()->status)->toBe(SalesOrderStatus::Draft);
});

test('a confirmed sales order cannot be cancelled or deleted', function () {
    $user = User::factory()->create();
    $order = SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed]);

    $cancelResponse = $this->actingAs($user)->post(route('sales-order.cancel', $order));
    $cancelResponse->assertInertiaFlash('toast.type', 'error');

    $deleteResponse = $this->actingAs($user)->delete(route('sales-order.delete', $order));
    $deleteResponse->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseHas('sales_orders', ['id' => $order->id]);
});
