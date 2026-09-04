<?php

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Domain\Facades\StockMovementFacade\StockMovementFacade;

/**
 * The stock ledger's write primitive, `StockMovementService::post()`.
 *
 * Nothing in the application calls this any more — stock is operated in the
 * BuyAbans back office, and every HTTP route that used to reach it is gone. It
 * survives because it is what wrote the history the `ledger` demand source
 * still reads, and because {@see InventoryDailySnapshotTest} builds its
 * fixtures with it.
 *
 * These tests moved here from the deleted `StockMovementTest` and
 * `InventoryBatchTest` write cases. They previously drove the same logic
 * through `POST /stock-movement/store`; the route is gone, the logic is not, so
 * they call the Facade directly rather than being deleted along with the route.
 */
function seedPair(): array
{
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 0,
        'available_qty' => 0,
        'average_cost' => 0,
    ]);

    return [$warehouse, $sku];
}

test('an inbound movement writes a ledger row and rolls the balance', function () {
    [$warehouse, $sku] = seedPair();

    $result = StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 100,
        'unit_cost' => 25,
        'occurred_at' => now(),
    ]);

    expect($result['success'])->toBeTrue();

    $this->assertDatabaseHas('stock_movements', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 100,
    ]);

    $balance = Inventory::where('warehouse_id', $warehouse->id)->where('sku_id', $sku->id)->first();

    expect((int) $balance->on_hand_qty)->toBe(100);
    expect((float) $balance->average_cost)->toBe(25.0);
});

test('a second inbound movement rolls the average cost forward', function () {
    [$warehouse, $sku] = seedPair();

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 100,
        'unit_cost' => 20,
        'occurred_at' => now()->subDay(),
    ]);

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_IN',
        'quantity' => 100,
        'unit_cost' => 30,
        'occurred_at' => now(),
    ]);

    $balance = Inventory::where('warehouse_id', $warehouse->id)->where('sku_id', $sku->id)->first();

    // Weighted average, not last-cost: (100x20 + 100x30) / 200.
    expect((int) $balance->on_hand_qty)->toBe(200);
    expect((float) $balance->average_cost)->toBe(25.0);
});

test('an outbound movement exceeding on-hand stock is refused, and the balance is untouched', function () {
    [$warehouse, $sku] = seedPair();

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 5,
        'unit_cost' => 10,
        'occurred_at' => now()->subDay(),
    ]);

    $result = StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_OUT',
        'quantity' => 50,
        'occurred_at' => now(),
    ]);

    expect($result['success'])->toBeFalse();

    $balance = Inventory::where('warehouse_id', $warehouse->id)->where('sku_id', $sku->id)->first();
    expect((int) $balance->on_hand_qty)->toBe(5);

    // `post()` does not open a transaction of its own — it writes the ledger row
    // first and then applies the balance, and every caller it ever had wrapped
    // it. So the refused row is still on the ledger. Asserted rather than
    // wished away, because it is the contract any future caller inherits.
    expect(StockMovement::where('movement_type', 'ADJUSTMENT_OUT')->count())->toBe(1);
});

test('movements open and consume FIFO batches oldest first', function () {
    [$warehouse, $sku] = seedPair();

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'OPENING_STOCK',
        'quantity' => 10,
        'unit_cost' => 10,
        'occurred_at' => now()->subDays(3),
    ]);

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_IN',
        'quantity' => 10,
        'unit_cost' => 15,
        'occurred_at' => now()->subDay(),
    ]);

    expect(InventoryBatch::count())->toBe(2);

    StockMovementFacade::post([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'ADJUSTMENT_OUT',
        'quantity' => 12,
        'occurred_at' => now(),
    ]);

    $batches = InventoryBatch::orderBy('id')->get();

    // Oldest batch drained first, then the newer one partially consumed —
    // drained, not deleted, so the lot history stays readable.
    expect((int) $batches[0]->remaining_qty)->toBe(0);
    expect((int) $batches[1]->remaining_qty)->toBe(8);
});
