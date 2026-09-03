<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('purchase-order.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the purchase order list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('purchase-order.index'));

    $response->assertOk();
});

test('a purchase order can be created with line items and totals are computed server-side', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('purchase-order.store'), [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'tax' => 10,
        'items' => [
            ['sku_id' => $sku->id, 'quantity' => 10, 'unit_cost' => 5],
        ],
    ]);

    $purchaseOrder = PurchaseOrder::firstOrFail();
    $response->assertRedirect(route('purchase-order.edit', $purchaseOrder));
    expect($purchaseOrder->status)->toBe(PurchaseOrderStatus::Draft);
    expect((float) $purchaseOrder->subtotal)->toBe(50.0);
    expect((float) $purchaseOrder->total)->toBe(60.0);
    $this->assertDatabaseHas('purchase_order_items', [
        'purchase_order_id' => $purchaseOrder->id,
        'sku_id' => $sku->id,
        'quantity' => 10,
        'line_total' => 50,
    ]);
});

test('approving then marking ordered moves the purchase order through its workflow and sets incoming quantity', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
    ]);
    $purchaseOrder->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 20,
        'unit_cost' => 5,
        'line_total' => 100,
    ]);

    $this->actingAs($user)->post(route('purchase-order.approve', $purchaseOrder));
    expect($purchaseOrder->fresh()->status)->toBe(PurchaseOrderStatus::Approved);

    $this->actingAs($user)->post(route('purchase-order.mark-ordered', $purchaseOrder));
    expect($purchaseOrder->fresh()->status)->toBe(PurchaseOrderStatus::Ordered);

    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'incoming_qty' => 20,
    ]);
});

test('a non-draft purchase order cannot be edited or deleted', function () {
    $user = User::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved]);

    $response = $this->actingAs($user)->post(route('purchase-order.update', $purchaseOrder), [
        'supplier_id' => $purchaseOrder->supplier_id,
        'warehouse_id' => $purchaseOrder->warehouse_id,
        'order_date' => now()->toDateString(),
        'items' => [['sku_id' => Sku::factory()->create()->id, 'quantity' => 1, 'unit_cost' => 1]],
    ]);
    $response->assertInertiaFlash('toast.type', 'error');

    $deleteResponse = $this->actingAs($user)->delete(route('purchase-order.delete', $purchaseOrder));
    $deleteResponse->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id]);
});

test('cancelling an ordered purchase order clears its incoming quantity', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->create([
        'warehouse_id' => $warehouse->id,
        'status' => PurchaseOrderStatus::Ordered,
    ]);
    $purchaseOrder->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 15,
        'unit_cost' => 5,
        'line_total' => 75,
    ]);
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'incoming_qty' => 15,
    ]);

    $this->actingAs($user)->post(route('purchase-order.cancel', $purchaseOrder));

    expect($purchaseOrder->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'incoming_qty' => 0,
    ]);
});
