<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('inventory-analytics.index'));
    $response->assertRedirect(route('login'));
});

test('there are no create, edit, update or delete routes for inventory analytics', function () {
    expect(Route::has('inventory-analytics.create'))->toBeFalse();
    expect(Route::has('inventory-analytics.edit'))->toBeFalse();
    expect(Route::has('inventory-analytics.store'))->toBeFalse();
    expect(Route::has('inventory-analytics.delete'))->toBeFalse();
});

test('authenticated users can view the analytics report with no data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('inventory-analytics.index'));

    $response->assertOk();
});

test('velocity, days of stock, turnover and reorder point are computed correctly', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $sku = Sku::factory()->create(['product_id' => $product->id]);
    $supplier = Supplier::factory()->create(['default_lead_time_days' => 10]);
    $supplier->supplierSkus()->create([
        'sku_id' => $sku->id,
        'unit_cost' => 20,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 15,
        'is_primary' => true,
        'status' => true,
    ]);
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 100,
        'available_qty' => 100,
    ]);
    InventoryBatch::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'received_date' => now()->subDays(20),
        'received_qty' => 100,
        'remaining_qty' => 100,
    ]);

    for ($i = 0; $i < 10; $i++) {
        StockMovement::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'movement_type' => 'SALE',
            'quantity' => 5,
            'occurred_at' => now()->subDays($i * 2),
        ]);
    }

    $response = $this->actingAs($user)->get(route('inventory-analytics.index', ['lookback_days' => 30, 'safety_days' => 7]));

    $response->assertOk();
    $rows = $response->getOriginalContent()->getData()['page']['props']['rows']['data'];
    $row = collect($rows)->firstWhere('sku_id', $sku->id);

    expect($row->units_sold_period)->toBe(50);
    expect($row->daily_velocity)->toEqualWithDelta(50 / 30, 0.001);
    expect($row->days_of_stock)->toEqualWithDelta(60, 0.1);
    expect($row->turnover_ratio)->toBe(0.5);
    expect($row->weighted_age_days)->toBe(20.0);
    expect($row->lead_time_days)->toBe(15);
    expect($row->reorder_point)->toBe((int) ceil((50 / 30) * (15 + 7)));
    expect($row->needs_reorder)->toBeFalse();
    expect($row->movement_speed)->toBe('fast');
});

test('a SKU with stock but no sales in the period is classified dead', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 40,
        'available_qty' => 40,
    ]);

    $response = $this->actingAs($user)->get(route('inventory-analytics.index'));

    $rows = $response->getOriginalContent()->getData()['page']['props']['rows']['data'];
    $row = collect($rows)->firstWhere('sku_id', $sku->id);

    expect($row->movement_speed)->toBe('dead');
    expect($row->abc_class)->toBe('unclassified');
    expect($row->days_of_stock)->toBeNull();
});

test('a sale within the window that exceeds available stock flags needs_reorder', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    $supplier = Supplier::factory()->create(['default_lead_time_days' => 30]);
    $supplier->supplierSkus()->create([
        'sku_id' => $sku->id,
        'unit_cost' => 5,
        'minimum_order_qty' => 1,
        'order_multiple' => 1,
        'expected_lead_time_days' => 30,
        'is_primary' => true,
        'status' => true,
    ]);
    Inventory::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'on_hand_qty' => 5,
        'available_qty' => 5,
    ]);
    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'movement_type' => 'SALE',
        'quantity' => 30,
        'occurred_at' => now()->subDays(1),
    ]);

    $response = $this->actingAs($user)->get(route('inventory-analytics.index'));

    $rows = $response->getOriginalContent()->getData()['page']['props']['rows']['data'];
    $row = collect($rows)->firstWhere('sku_id', $sku->id);

    expect($row->needs_reorder)->toBeTrue();
});

test('filtering by warehouse only returns that warehouse\'s rows', function () {
    $user = User::factory()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $skuA = Sku::factory()->create();
    $skuB = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouseA->id, 'sku_id' => $skuA->id]);
    Inventory::factory()->create(['warehouse_id' => $warehouseB->id, 'sku_id' => $skuB->id]);

    $response = $this->actingAs($user)->get(route('inventory-analytics.index', ['warehouse_id' => $warehouseA->id]));

    $rows = $response->getOriginalContent()->getData()['page']['props']['rows']['data'];

    expect(collect($rows)->pluck('warehouse_id')->unique()->all())->toBe([$warehouseA->id]);
});

test('a confirmed sale within the window contributes to revenue for ABC ranking', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['warehouse_id' => $warehouse->id, 'sku_id' => $sku->id, 'on_hand_qty' => 10, 'available_qty' => 10]);
    $order = SalesOrder::factory()->create([
        'warehouse_id' => $warehouse->id,
        'status' => 'confirmed',
        'order_date' => now()->subDays(3),
    ]);
    $order->items()->create([
        'sku_id' => $sku->id,
        'quantity' => 2,
        'unit_price' => 100,
        'discount' => 0,
        'net_amount' => 200,
    ]);

    $response = $this->actingAs($user)->get(route('inventory-analytics.index'));

    $rows = $response->getOriginalContent()->getData()['page']['props']['rows']['data'];
    $row = collect($rows)->firstWhere('sku_id', $sku->id);

    expect($row->revenue_period)->toBe(200.0);
    expect($row->abc_class)->not->toBe('unclassified');
});
