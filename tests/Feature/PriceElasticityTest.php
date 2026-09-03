<?php

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Sku;
use App\Models\User;
use Domain\Facades\PriceElasticityFacade\PriceElasticityFacade;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('price-elasticity.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the price elasticity report', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('price-elasticity.index'));

    $response->assertOk();
});

test('estimates a real elasticity coefficient from two distinct historical prices', function () {
    $sku = Sku::factory()->create();

    $orderAtTenDollars = SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed]);
    SalesOrderItem::factory()->create([
        'sales_order_id' => $orderAtTenDollars->id,
        'sku_id' => $sku->id,
        'unit_price' => 10,
        'quantity' => 100,
    ]);

    $orderAtTwentyDollars = SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed]);
    SalesOrderItem::factory()->create([
        'sales_order_id' => $orderAtTwentyDollars->id,
        'sku_id' => $sku->id,
        'unit_price' => 20,
        'quantity' => 25,
    ]);

    // Hand-computed: doubling price (10 -> 20) while quantity drops to a
    // quarter (100 -> 25) is the textbook elasticity of exactly -2.0
    // (ln(1/4) / ln(2) = -2).
    $rows = PriceElasticityFacade::report();

    expect($rows->total())->toBe(1);
    $row = $rows->items()[0];
    expect($row->sku_id)->toBe($sku->id);
    expect($row->distinct_price_points)->toBe(2);
    expect($row->elasticity)->toBe(-2.0);
    expect($row->interpretation)->toContain('Elastic');
});

test('a sku that has only ever sold at one price is excluded, not given a fabricated coefficient', function () {
    $sku = Sku::factory()->create();
    $order = SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed]);

    SalesOrderItem::factory()->create([
        'sales_order_id' => $order->id,
        'sku_id' => $sku->id,
        'unit_price' => 15,
        'quantity' => 10,
    ]);

    $rows = PriceElasticityFacade::report();

    expect($rows->total())->toBe(0);
});

test('a draft (unconfirmed) sales order is not counted as a real price observation', function () {
    $sku = Sku::factory()->create();

    $draftOrder = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft]);
    SalesOrderItem::factory()->create([
        'sales_order_id' => $draftOrder->id,
        'sku_id' => $sku->id,
        'unit_price' => 10,
        'quantity' => 100,
    ]);

    $confirmedOrder = SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed]);
    SalesOrderItem::factory()->create([
        'sales_order_id' => $confirmedOrder->id,
        'sku_id' => $sku->id,
        'unit_price' => 20,
        'quantity' => 25,
    ]);

    // Only one confirmed price point exists — not enough real data.
    $rows = PriceElasticityFacade::report();

    expect($rows->total())->toBe(0);
});
