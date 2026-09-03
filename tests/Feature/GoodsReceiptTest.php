<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('goods-receipt.index'));
    $response->assertRedirect(route('login'));
});

test('there are no edit, update or delete routes for goods receipts', function () {
    expect(Route::has('goods-receipt.edit'))->toBeFalse();
    expect(Route::has('goods-receipt.update'))->toBeFalse();
    expect(Route::has('goods-receipt.delete'))->toBeFalse();
});

test('recording a full receipt marks the purchase order received and updates the inventory balance and batch', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'status' => PurchaseOrderStatus::Ordered,
    ]);
    $item = $purchaseOrder->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 30,
        'unit_cost' => 8,
        'line_total' => 240,
    ]);

    $response = $this->actingAs($user)->post(route('goods-receipt.store'), [
        'purchase_order_id' => $purchaseOrder->id,
        'warehouse_id' => $warehouse->id,
        'received_date' => now()->toDateString(),
        'items' => [
            [
                'purchase_order_item_id' => $item->id,
                'sku_id' => $sku->id,
                'received_qty' => 30,
                'unit_cost' => 8,
            ],
        ],
    ]);

    $response->assertRedirect(route('goods-receipt.index'));
    expect($purchaseOrder->fresh()->status)->toBe(PurchaseOrderStatus::Received);
    $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id, 'received_qty' => 30]);
    $this->assertDatabaseHas('stock_movements', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'PURCHASE_RECEIPT',
        'quantity' => 30,
        'reference_type' => 'goods_receipt',
    ]);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 30,
        'incoming_qty' => 0,
    ]);
    $this->assertDatabaseHas('inventory_batches', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'received_qty' => 30,
        'remaining_qty' => 30,
        'unit_cost' => 8,
    ]);
});

test('a partial receipt leaves the purchase order partially received', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create([
        'warehouse_id' => $warehouse->id,
        'status' => PurchaseOrderStatus::Ordered,
    ]);
    $item = $purchaseOrder->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 30,
        'unit_cost' => 8,
        'line_total' => 240,
    ]);

    $this->actingAs($user)->post(route('goods-receipt.store'), [
        'purchase_order_id' => $purchaseOrder->id,
        'warehouse_id' => $warehouse->id,
        'received_date' => now()->toDateString(),
        'items' => [
            [
                'purchase_order_item_id' => $item->id,
                'sku_id' => $sku->id,
                'received_qty' => 10,
                'unit_cost' => 8,
            ],
        ],
    ]);

    expect($purchaseOrder->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

test('receiving against the wrong warehouse is rejected', function () {
    $user = User::factory()->create();
    $orderedWarehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create([
        'warehouse_id' => $orderedWarehouse->id,
        'status' => PurchaseOrderStatus::Ordered,
    ]);
    $item = $purchaseOrder->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 10,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    $response = $this->actingAs($user)->post(route('goods-receipt.store'), [
        'purchase_order_id' => $purchaseOrder->id,
        'warehouse_id' => $otherWarehouse->id,
        'received_date' => now()->toDateString(),
        'items' => [
            [
                'purchase_order_item_id' => $item->id,
                'sku_id' => $sku->id,
                'received_qty' => 10,
                'unit_cost' => 5,
            ],
        ],
    ]);

    $response->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseCount('goods_receipts', 0);
});
