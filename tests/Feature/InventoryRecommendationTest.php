<?php

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\Forecast;
use App\Models\Inventory;
use App\Models\InventoryRecommendation;
use App\Models\MlForecastRun;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SupplierSku;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\InventoryRecommendationFacade\InventoryRecommendationFacade;
use Illuminate\Support\Facades\DB;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('inventory-recommendation.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the recommendation list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('inventory-recommendation.index'));

    $response->assertOk();
});

test('generates a purchase recommendation from the reorder-point formula', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
        'confidence_score' => 80,
    ]);

    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 50,
        'order_multiple' => 10,
        'expected_lead_time_days' => 20,
    ]);

    // Hand-computed: daily rate 300/30=10/day. No snapshot history, so
    // safety stock falls back to 10 * 7 = 70. Lead-time demand = 10*20=200.
    // Reorder point = ceil(200+70) = 270. Inventory position = 100, already
    // at/below the reorder point, so it's urgent today. Order-up-to target
    // = 270 + 10*14 = 410; raw need = 410-100 = 310, already an exact
    // multiple of the order_multiple (10) and above the MOQ (50) → 310.
    // Days until stockout = floor(100/10) = 10, which is <= the 20-day lead
    // time, so this is Critical, not just Moderate.
    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'recommendation_type' => 'PURCHASE',
        'current_qty' => 100,
        'recommended_qty' => 310,
        'stockout_risk' => 'CRITICAL',
        'confidence_score' => 80,
        'status' => 'NEW',
    ]);

    $recommendation = InventoryRecommendation::firstOrFail();
    expect((float) $recommendation->forecast_30d)->toBe(300.0);
    expect($recommendation->recommended_action_date->toDateString())->toBe(now()->toDateString());
});

test('skips a pair with no forecast at all', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseCount('inventory_recommendations', 0);
});

test('skips a pair with a forecast but no primary supplier', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
    ]);

    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseCount('inventory_recommendations', 0);
});

test('skips a pair that is not urgent and has no ageing or overstock concern', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    // 50 on hand, 1/day demand, no batch history: reorder point is 12
    // (5-day lead time demand + a 7-day fallback safety buffer), so this
    // pair is nowhere near needing a purchase (38 days of runway against a
    // 5-day lead time) — but it also isn't overstocked (1.67 months of
    // stock, below the 3-month Moderate threshold) or ageing (no batch
    // data and 90 days of demand alone would clear the entire balance), so
    // nothing at all should be generated for it.
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 50,
        'available_qty' => 50,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 30,
    ]);

    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 5,
    ]);

    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseCount('inventory_recommendations', 0);
});

test('a heavily overstocked pair with low sell-through gets a clearance recommendation', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 1000,
        'available_qty' => 1000,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 30,
    ]);

    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 5,
    ]);

    // Hand-computed: daily rate 1/day. 90-day projected demand = 90, but
    // available stock is 1000 — sell-through = 90/1000 = 9%, well under the
    // 30% "low" threshold. No batch history, so ageing risk is purely the
    // demand component: 100 - min(100, 90/1000*100) = 91. That clears the
    // 75-point Clearance threshold, and months-of-stock (1000/30 ≈ 33.3) is
    // deep into Critical overstock territory.
    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'recommendation_type' => 'CLEARANCE',
        'recommended_qty' => 0,
        'ageing_risk' => 91,
        'overstock_risk' => 'CRITICAL',
    ]);
});

test('a moderately overstocked pair without severe ageing gets a do-not-reorder recommendation', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 150,
        'available_qty' => 150,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 30,
    ]);

    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 5,
    ]);

    // Hand-computed: daily rate 1/day, 150 on hand => months of stock =
    // 150/30 = 5, in the Moderate overstock band (>= 3, < 6). Sell-through
    // = 90/150 = 60%, well above the 30% Clearance threshold, so this
    // should land on DoNotReorder, not Clearance.
    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'recommendation_type' => 'DO_NOT_REORDER',
        'recommended_qty' => 0,
        'overstock_risk' => 'MODERATE',
    ]);
});

test('a pair with no forecasted demand but real stock on hand is flagged for clearance or do-not-reorder', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 40,
        'available_qty' => 40,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 0,
    ]);

    // No supplier configured at all — zero demand alone should still be
    // enough to flag this pair without needing a lead time to compute
    // urgency from.
    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'overstock_risk' => 'CRITICAL',
    ]);
    expect(InventoryRecommendation::first()->recommendation_type)
        ->toBeIn([RecommendationType::Clearance, RecommendationType::DoNotReorder]);
});

test('an urgent purchase with elevated ageing risk becomes a reduce-purchase recommendation', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
        'confidence_score' => 80,
    ]);

    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 45,
    ]);

    // Batches aged well past a year push the ageing risk score above the
    // 50-point ReducePurchase threshold even though the pair is genuinely
    // urgent (10/day demand against a 45-day lead time, 100 on hand).
    DB::table('inventory_batches')->insert([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'received_date' => now()->subDays(500)->toDateString(),
        'received_qty' => 100,
        'remaining_qty' => 100,
        'unit_cost' => 10,
        'source_type' => 'purchase_order',
        'source_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $recommendation = InventoryRecommendation::firstOrFail();
    expect($recommendation->recommendation_type)->toBe(RecommendationType::ReducePurchase);
    expect($recommendation->recommended_qty)->toBeGreaterThan(0);
    expect($recommendation->ageing_risk)->toBeGreaterThanOrEqual(50);
});

test('generate never modifies an already-decided recommendation for the same pair', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
    ]);

    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
    ]);

    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 50,
        'order_multiple' => 10,
        'expected_lead_time_days' => 20,
    ]);

    $existing = InventoryRecommendation::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'status' => RecommendationStatus::Accepted,
        'recommended_qty' => 999,
    ]);

    InventoryRecommendationFacade::generate();

    expect(InventoryRecommendation::count())->toBe(1);
    $existing->refresh();
    expect($existing->recommended_qty)->toBe(999);
    expect($existing->status)->toBe(RecommendationStatus::Accepted);
});

test('generate removes an open recommendation whose pair no longer needs one', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $stale = InventoryRecommendation::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'status' => RecommendationStatus::New,
    ]);

    InventoryRecommendationFacade::generate();

    $this->assertDatabaseMissing('inventory_recommendations', ['id' => $stale->id]);
});

test('accepting a recommendation records the decision', function () {
    $user = User::factory()->create();
    $recommendation = InventoryRecommendation::factory()->create(['recommended_qty' => 120]);

    $response = $this->actingAs($user)->post(route('inventory-recommendation.accept', $recommendation->id));

    $response->assertRedirect();
    $recommendation->refresh();
    expect($recommendation->status)->toBe(RecommendationStatus::Accepted);
    expect($recommendation->decided_qty)->toBe(120);
    expect($recommendation->decided_by)->toBe($user->id);
    expect($recommendation->decided_at)->not->toBeNull();
});

test('modifying a recommendation overrides the quantity with a reason', function () {
    $user = User::factory()->create();
    $recommendation = InventoryRecommendation::factory()->create(['recommended_qty' => 300]);

    $response = $this->actingAs($user)->post(route('inventory-recommendation.modify', $recommendation->id), [
        'decided_qty' => 180,
        'reason' => 'Budget limitation',
    ]);

    $response->assertRedirect();
    $recommendation->refresh();
    expect($recommendation->status)->toBe(RecommendationStatus::Modified);
    expect($recommendation->decided_qty)->toBe(180);
    expect($recommendation->decision_reason)->toBe('Budget limitation');
});

test('rejecting a recommendation records a zero decided quantity', function () {
    $user = User::factory()->create();
    $recommendation = InventoryRecommendation::factory()->create();

    $response = $this->actingAs($user)->post(route('inventory-recommendation.reject', $recommendation->id), [
        'reason' => 'Model being replaced',
    ]);

    $response->assertRedirect();
    $recommendation->refresh();
    expect($recommendation->status)->toBe(RecommendationStatus::Rejected);
    expect($recommendation->decided_qty)->toBe(0);
    expect($recommendation->decision_reason)->toBe('Model being replaced');
});

test('a shortage in one warehouse is matched against real surplus in another and becomes a transfer', function () {
    $sku = Sku::factory()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);

    // Warehouse A: identical to "generates a purchase recommendation from
    // the reorder-point formula" above — hand-computed raw need of 310
    // before this pair's already-known Critical stockout risk.
    Inventory::factory()->create([
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
    ]);
    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 50,
        'order_multiple' => 10,
        'expected_lead_time_days' => 20,
    ]);

    // Warehouse B: no supplier at all — deliberately, to prove a transfer
    // source doesn't need one. 1/day demand against 2000 on hand is a
    // Clearance-worthy surplus (90-day forecast is only 90 units), leaving
    // 1910 units of real surplus — comfortably above A's 310 need.
    Inventory::factory()->create([
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 2000,
        'available_qty' => 2000,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 30,
    ]);

    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'source_warehouse_id' => $warehouseB->id,
        'recommendation_type' => 'TRANSFER_STOCK',
        'recommended_qty' => 310,
        'stockout_risk' => 'CRITICAL',
    ]);
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'source_warehouse_id' => null,
        'recommendation_type' => 'CLEARANCE',
        'recommended_qty' => 0,
    ]);

    $destination = InventoryRecommendation::where('warehouse_id', $warehouseA->id)->firstOrFail();
    expect($destination->reason)->toContain((string) 310);
});

test('a destination need larger than any single warehouse surplus keeps its ordinary purchase', function () {
    $sku = Sku::factory()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);

    Inventory::factory()->create([
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
    ]);
    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 50,
        'order_multiple' => 10,
        'expected_lead_time_days' => 20,
    ]);

    // Warehouse B: Moderate overstock (3.33 months of stock), but its real
    // surplus (available minus its own 90-day forecast) is only 10 units —
    // far short of A's 310 need, so no single-warehouse transfer can cover
    // it and A should keep its plain Purchase.
    Inventory::factory()->create([
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 30,
    ]);

    $result = InventoryRecommendationFacade::generate();

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'source_warehouse_id' => null,
        'recommendation_type' => 'PURCHASE',
        'recommended_qty' => 310,
    ]);
    $this->assertDatabaseHas('inventory_recommendations', [
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'recommendation_type' => 'DO_NOT_REORDER',
    ]);
});

test('generate clears a stale transfer source once the surplus that backed it disappears', function () {
    $sku = Sku::factory()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);

    Inventory::factory()->create([
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseA->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
    ]);
    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 50,
        'order_multiple' => 10,
        'expected_lead_time_days' => 20,
    ]);

    Inventory::factory()->create([
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 2000,
        'available_qty' => 2000,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseB->id,
        'sku_id' => $sku->id,
        'horizon_days' => 30,
        'predicted_qty' => 30,
    ]);

    InventoryRecommendationFacade::generate();

    $destination = InventoryRecommendation::where('warehouse_id', $warehouseA->id)->firstOrFail();
    expect($destination->recommendation_type)->toBe(RecommendationType::TransferStock);
    expect($destination->source_warehouse_id)->toBe($warehouseB->id);

    // Warehouse B's surplus evaporates — no longer overstocked or aging.
    Inventory::where('warehouse_id', $warehouseB->id)->where('sku_id', $sku->id)
        ->update(['on_hand_qty' => 20, 'available_qty' => 20]);

    InventoryRecommendationFacade::generate();

    $destination->refresh();
    expect($destination->recommendation_type)->toBe(RecommendationType::Purchase);
    expect($destination->source_warehouse_id)->toBeNull();
});

test('central allocation groups open purchase-type recommendations across warehouses for the same SKU', function () {
    $sku = Sku::factory()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $warehouseC = Warehouse::factory()->create();
    $run = MlForecastRun::factory()->create(['horizon_days' => 30]);
    $supplier = Supplier::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $sku->id,
        'is_primary' => true,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 5,
    ]);

    foreach ([$warehouseA, $warehouseB] as $warehouse) {
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'on_hand_qty' => 10,
            'available_qty' => 10,
            'incoming_qty' => 0,
            'reserved_qty' => 0,
        ]);
        Forecast::factory()->create([
            'forecast_run_id' => $run->id,
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'horizon_days' => 30,
            'predicted_qty' => 300,
        ]);
    }

    // A second SKU only needed in one warehouse must not appear — nothing
    // to allocate across locations when there's only one location.
    $otherSku = Sku::factory()->create();
    SupplierSku::factory()->create([
        'supplier_id' => $supplier->id,
        'sku_id' => $otherSku->id,
        'is_primary' => true,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 5,
    ]);
    Inventory::factory()->create([
        'warehouse_id' => $warehouseC->id,
        'sku_id' => $otherSku->id,
        'on_hand_qty' => 10,
        'available_qty' => 10,
        'incoming_qty' => 0,
        'reserved_qty' => 0,
    ]);
    Forecast::factory()->create([
        'forecast_run_id' => $run->id,
        'warehouse_id' => $warehouseC->id,
        'sku_id' => $otherSku->id,
        'horizon_days' => 30,
        'predicted_qty' => 300,
    ]);

    InventoryRecommendationFacade::generate();

    $rows = InventoryRecommendationFacade::centralAllocation();

    expect($rows->total())->toBe(1);
    $row = $rows->items()[0];
    expect($row->sku_id)->toBe($sku->id);
    expect($row->warehouse_count)->toBe(2);
    expect($row->total_recommended_qty)->toBe(500);
    expect($row->breakdown)->toHaveCount(2);
});

test('guests are redirected to the login page for central allocation', function () {
    $response = $this->get(route('inventory-recommendation.central-allocation'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the central allocation page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('inventory-recommendation.central-allocation'));

    $response->assertOk();
});

test('reviewing a recommendation does not decide it', function () {
    $user = User::factory()->create();
    $recommendation = InventoryRecommendation::factory()->create();

    $response = $this->actingAs($user)->post(route('inventory-recommendation.review', $recommendation->id));

    $response->assertRedirect();
    $recommendation->refresh();
    expect($recommendation->status)->toBe(RecommendationStatus::Reviewed);
    expect($recommendation->decided_qty)->toBeNull();
});
