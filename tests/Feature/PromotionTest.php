<?php

use App\Models\InventoryDailySnapshot;
use App\Models\Promotion;
use App\Models\Sku;
use App\Models\User;
use Domain\Facades\PromotionFacade\PromotionFacade;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('promotion.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the promotion list, create and edit pages', function () {
    $user = User::factory()->create();
    $promotion = Promotion::factory()->create();

    $this->actingAs($user)->get(route('promotion.index'))->assertOk();
    $this->actingAs($user)->get(route('promotion.create'))->assertOk();
    $this->actingAs($user)->get(route('promotion.edit', $promotion->id))->assertOk();
});

test('creating a promotion syncs the selected skus', function () {
    $user = User::factory()->create();
    $skuA = Sku::factory()->create();
    $skuB = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('promotion.store'), [
        'name' => 'Summer sale',
        'discount_type' => 'PERCENTAGE',
        'discount_value' => 20,
        'start_date' => now()->addDays(5)->toDateString(),
        'end_date' => now()->addDays(12)->toDateString(),
        'sku_ids' => [$skuA->id, $skuB->id],
    ]);

    $response->assertRedirect();
    $promotion = Promotion::firstOrFail();
    expect($promotion->name)->toBe('Summer sale');
    expect($promotion->skus()->pluck('skus.id')->sort()->values()->all())
        ->toBe([$skuA->id, $skuB->id]);
});

test('a percentage discount over 100 is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('promotion.store'), [
        'name' => 'Too much off',
        'discount_type' => 'PERCENTAGE',
        'discount_value' => 150,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
    ]);

    $response->assertSessionHasErrors('discount_value');
});

test('an end date before the start date is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('promotion.store'), [
        'name' => 'Backwards dates',
        'discount_type' => 'FIXED',
        'discount_value' => 10,
        'start_date' => now()->addDays(10)->toDateString(),
        'end_date' => now()->toDateString(),
    ]);

    $response->assertSessionHasErrors('end_date');
});

test('updating a promotion replaces its sku assignments', function () {
    $user = User::factory()->create();
    $promotion = Promotion::factory()->create();
    $originalSku = Sku::factory()->create();
    $promotion->skus()->attach($originalSku->id);

    $newSku = Sku::factory()->create();

    $response = $this->actingAs($user)->post(route('promotion.update', $promotion->id), [
        'name' => $promotion->name,
        'discount_type' => $promotion->discount_type->value,
        'discount_value' => $promotion->discount_value,
        'start_date' => $promotion->start_date->toDateString(),
        'end_date' => $promotion->end_date->toDateString(),
        'sku_ids' => [$newSku->id],
    ]);

    $response->assertRedirect();
    $promotion->refresh();
    expect($promotion->skus()->pluck('skus.id')->all())->toBe([$newSku->id]);
});

test('deleting a promotion soft-deletes it', function () {
    $user = User::factory()->create();
    $promotion = Promotion::factory()->create();

    $this->actingAs($user)->delete(route('promotion.delete', $promotion->id));

    $this->assertSoftDeleted('promotions', ['id' => $promotion->id]);
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
