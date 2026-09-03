<?php

use App\Enums\StockTransferStatus;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Sku;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('stock-transfer.index'));
    $response->assertRedirect(route('login'));
});

test('a stock transfer cannot use the same warehouse as source and destination', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('stock-transfer.store'), [
        'source_warehouse_id' => $warehouse->id,
        'destination_warehouse_id' => $warehouse->id,
        'transfer_date' => now()->toDateString(),
        'items' => [['sku_id' => $sku->id, 'quantity' => 5]],
    ]);

    $response->assertSessionHasErrors('source_warehouse_id');
});

test('a stock transfer can be created with line items', function () {
    $user = User::factory()->create();
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('stock-transfer.store'), [
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $destination->id,
        'transfer_date' => now()->toDateString(),
        'items' => [['sku_id' => $sku->id, 'quantity' => 5]],
    ]);

    $transfer = StockTransfer::firstOrFail();
    $response->assertRedirect(route('stock-transfer.edit', $transfer));
    expect($transfer->status)->toBe(StockTransferStatus::Draft);
    $this->assertDatabaseHas('stock_transfer_items', [
        'stock_transfer_id' => $transfer->id,
        'sku_id' => $sku->id,
        'quantity' => 5,
    ]);
});

test('dispatching then receiving a transfer moves stock between warehouses carrying the original cost', function () {
    $user = User::factory()->create();
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $source->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 50,
        'available_qty' => 50,
        'average_cost' => 12,
    ]);
    InventoryBatch::factory()->create([
        'warehouse_id' => $source->id,
        'sku_id' => $sku->id,
        'received_qty' => 50,
        'remaining_qty' => 50,
        'unit_cost' => 12,
    ]);

    $transfer = StockTransfer::factory()->create([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $destination->id,
        'status' => StockTransferStatus::Draft,
    ]);
    $transfer->items()->create(['sku_id' => $sku->id, 'quantity' => 20]);

    $this->actingAs($user)->post(route('stock-transfer.approve', $transfer));
    expect($transfer->fresh()->status)->toBe(StockTransferStatus::Approved);

    $this->actingAs($user)->post(route('stock-transfer.dispatch', $transfer));
    expect($transfer->fresh()->status)->toBe(StockTransferStatus::Dispatched);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $source->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 30,
    ]);

    $this->actingAs($user)->post(route('stock-transfer.receive', $transfer));
    expect($transfer->fresh()->status)->toBe(StockTransferStatus::Received);
    $this->assertDatabaseHas('inventories', [
        'warehouse_id' => $destination->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 20,
        'average_cost' => 12,
    ]);
    $this->assertDatabaseHas('inventory_batches', [
        'warehouse_id' => $destination->id,
        'sku_id' => $sku->id,
        'received_qty' => 20,
        'unit_cost' => 12,
    ]);
});

test('a dispatched transfer can no longer be cancelled', function () {
    $user = User::factory()->create();
    $transfer = StockTransfer::factory()->create(['status' => StockTransferStatus::Dispatched]);

    $response = $this->actingAs($user)->post(route('stock-transfer.cancel', $transfer));

    $response->assertInertiaFlash('toast.type', 'error');
    expect($transfer->fresh()->status)->toBe(StockTransferStatus::Dispatched);
});
