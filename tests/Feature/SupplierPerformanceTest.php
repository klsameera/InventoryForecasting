<?php

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierPerformanceMetric;
use App\Models\User;
use Domain\Facades\SupplierPerformanceFacade\SupplierPerformanceFacade;
use Illuminate\Support\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('supplier-performance.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the supplier performance list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('supplier-performance.index'));

    $response->assertOk();
});

test('captures real lead time, fill rate and on-time percentage from actual PO and receipt dates', function () {
    // Matches app_plan.md's own worked example almost exactly: a supplier
    // configured for 15 days that actually takes 20-25 days.
    $supplier = Supplier::factory()->create(['default_lead_time_days' => 15]);

    $poOne = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_date' => '2026-07-01',
        'expected_date' => '2026-07-16',
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $poOne->id,
        'quantity' => 100,
        'received_qty' => 100,
    ]);
    GoodsReceipt::factory()->create([
        'purchase_order_id' => $poOne->id,
        'received_date' => '2026-07-21',
    ]);

    $poTwo = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_date' => '2026-07-05',
        'expected_date' => '2026-07-20',
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $poTwo->id,
        'quantity' => 50,
        'received_qty' => 40,
    ]);
    GoodsReceipt::factory()->create([
        'purchase_order_id' => $poTwo->id,
        'received_date' => '2026-07-30',
    ]);

    // Hand-computed: ordered 150, received 140, fill rate 93.33%. Lead
    // times 20 and 25 days -> average 22.5, std dev sqrt(12.5) = 3.54.
    // Both receipts arrived after their expected_date, so on-time is 0%.
    $result = SupplierPerformanceFacade::capturePeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('supplier_performance_metrics', [
        'supplier_id' => $supplier->id,
        'ordered_qty' => 150,
        'received_qty' => 140,
        'fill_rate' => 93.33,
        'average_lead_time_days' => 22.5,
        'lead_time_std_dev' => 3.54,
        'on_time_percentage' => 0,
        'quality_issue_rate' => null,
    ]);
});

test('a purchase order with no receipt yet does not count toward lead time or on-time percentage', function () {
    $supplier = Supplier::factory()->create();

    $po = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_date' => '2026-07-01',
        'expected_date' => '2026-07-16',
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'quantity' => 100,
        'received_qty' => 0,
    ]);

    $result = SupplierPerformanceFacade::capturePeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

    expect($result['success'])->toBeTrue();
    $this->assertDatabaseHas('supplier_performance_metrics', [
        'supplier_id' => $supplier->id,
        'ordered_qty' => 100,
        'received_qty' => 0,
        'fill_rate' => 0,
        'average_lead_time_days' => null,
        'on_time_percentage' => null,
    ]);
});

test('capturing the same period twice updates the existing row instead of duplicating it', function () {
    $supplier = Supplier::factory()->create();

    $po = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'order_date' => '2026-07-01',
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'quantity' => 100,
        'received_qty' => 100,
    ]);
    GoodsReceipt::factory()->create(['purchase_order_id' => $po->id, 'received_date' => '2026-07-10']);

    SupplierPerformanceFacade::capturePeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
    SupplierPerformanceFacade::capturePeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

    expect(SupplierPerformanceMetric::where('supplier_id', $supplier->id)->count())->toBe(1);
});
