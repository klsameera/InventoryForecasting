<?php

use App\Models\PurchaseOrder;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('supplier.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the supplier list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('supplier.index'));

    $response->assertOk();
});

test('a supplier can be created with nested supplier SKUs', function () {
    $user = User::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('supplier.store'), [
        'name' => 'Acme Supplies',
        'status' => true,
        'default_lead_time_days' => 14,
        'supplier_skus' => [
            [
                'sku_id' => $sku->id,
                'unit_cost' => 12.5,
                'minimum_order_qty' => 5,
                'is_primary' => true,
            ],
        ],
    ]);

    $response->assertRedirect(route('supplier.index'));
    $this->assertDatabaseHas('suppliers', ['name' => 'Acme Supplies']);
    $this->assertDatabaseHas('supplier_skus', [
        'sku_id' => $sku->id,
        'unit_cost' => 12.5,
        'is_primary' => true,
    ]);
});

test('marking a supplier SKU primary clears the primary flag on other suppliers for that SKU', function () {
    $user = User::factory()->create();
    $sku = Sku::factory()->create();
    $existingSupplier = Supplier::factory()->create();
    $existingSupplier->supplierSkus()->create([
        'sku_id' => $sku->id,
        'unit_cost' => 10,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'is_primary' => true,
        'status' => true,
    ]);

    $this->actingAs($user)->post(route('supplier.store'), [
        'name' => 'New Primary Supplier',
        'status' => true,
        'default_lead_time_days' => 7,
        'supplier_skus' => [
            ['sku_id' => $sku->id, 'unit_cost' => 9, 'is_primary' => true],
        ],
    ]);

    $this->assertDatabaseHas('supplier_skus', [
        'supplier_id' => $existingSupplier->id,
        'sku_id' => $sku->id,
        'is_primary' => false,
    ]);
});

test('a supplier with purchase orders cannot be deleted', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => Warehouse::factory()->create()->id,
    ]);

    $response = $this->actingAs($user)->delete(route('supplier.delete', $supplier));

    $response->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
});
