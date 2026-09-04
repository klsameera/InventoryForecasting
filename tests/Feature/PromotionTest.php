<?php

use App\Models\InventoryDailySnapshot;
use App\Models\Promotion;
use App\Models\Sku;
use Domain\Facades\PromotionFacade\PromotionFacade;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('promotion.index'));
    $response->assertRedirect(route('login'));
});

test('impact is not computed until the promotion has actually ended', function () {
    $promotion = Promotion::factory()->create([
        'start_date' => now()->addDays(5),
        'end_date' => now()->addDays(12),
    ]);

    $result = PromotionFacade::impact($promotion->id);

    expect($result['success'])->toBeTrue();
    expect($result['data']['elapsed'])->toBeFalse();
    expect($result['data']['skus'])->toBe([]);
});

test('impact compares real during-promotion demand against a matching pre-promotion baseline', function () {
    $sku = Sku::factory()->create();

    $promotion = Promotion::factory()->create([
        'start_date' => now()->subDays(20),
        'end_date' => now()->subDays(11),
    ]);
    $promotion->skus()->attach($sku->id);

    // Baseline window: 21-30 days ago (10 days), 5/day.
    for ($daysAgo = 21; $daysAgo <= 30; $daysAgo++) {
        InventoryDailySnapshot::factory()->create([
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($daysAgo),
            'sold_qty' => 5,
        ]);
    }

    // During window: the promotion's own 10 days, 10/day (double).
    for ($daysAgo = 11; $daysAgo <= 20; $daysAgo++) {
        InventoryDailySnapshot::factory()->create([
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($daysAgo),
            'sold_qty' => 10,
        ]);
    }

    // Hand-computed: baseline daily rate 5.0, during daily rate 10.0,
    // percent change = (10-5)/5 * 100 = 100%.
    $result = PromotionFacade::impact($promotion->id);

    expect($result['data']['elapsed'])->toBeTrue();
    $row = $result['data']['skus'][0];
    expect($row['sku_id'])->toBe($sku->id);
    expect($row['baseline_daily_rate'])->toBe(5.0);
    expect($row['during_daily_rate'])->toBe(10.0);
    expect($row['percent_change'])->toBe(100.0);
});

test('impact reports no baseline demand rather than a divide-by-zero when the sku had zero sales before the promotion', function () {
    $sku = Sku::factory()->create();

    $promotion = Promotion::factory()->create([
        'start_date' => now()->subDays(20),
        'end_date' => now()->subDays(11),
    ]);
    $promotion->skus()->attach($sku->id);

    for ($daysAgo = 11; $daysAgo <= 20; $daysAgo++) {
        InventoryDailySnapshot::factory()->create([
            'sku_id' => $sku->id,
            'snapshot_date' => now()->subDays($daysAgo),
            'sold_qty' => 10,
        ]);
    }

    $result = PromotionFacade::impact($promotion->id);

    $row = $result['data']['skus'][0];
    expect($row['baseline_daily_rate'])->toBe(0.0);
    expect($row['percent_change'])->toBeNull();
});

test('Promotion has no create, edit or write routes', function () {
    // This application displays this data; the BuyAbans back office owns it.
    // These route names are asserted absent rather than asserted forbidden:
    // the routes were removed, not disabled, so nothing dead is left behind.
    foreach (['create', 'edit', 'store', 'update', 'delete'] as $action) {
        expect(Route::has('promotion.'.$action))->toBeFalse();
    }
});
