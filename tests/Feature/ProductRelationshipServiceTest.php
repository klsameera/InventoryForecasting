<?php

use App\Enums\MovementType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryDailySnapshot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Domain\Facades\ProductRelationshipFacade\ProductRelationshipFacade;

test('guests are redirected to the login page for all three product-relationship pages', function () {
    $this->get(route('product-relationship.similarity'))->assertRedirect(route('login'));
    $this->get(route('product-relationship.cannibalization'))->assertRedirect(route('login'));
    $this->get(route('product-relationship.successors'))->assertRedirect(route('login'));
});

test('authenticated users can view all three product-relationship pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('product-relationship.similarity'))->assertOk();
    $this->actingAs($user)->get(route('product-relationship.cannibalization'))->assertOk();
    $this->actingAs($user)->get(route('product-relationship.successors'))->assertOk();
});

test('similarSkus falls back to plain category when no brand or size match exists', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $sku = Sku::factory()->create(['product_id' => $product->id]);

    $peerProduct = Product::factory()->create(['category_id' => $category->id]);
    $peerSku = Sku::factory()->create(['product_id' => $peerProduct->id]);

    $result = ProductRelationshipFacade::similarSkus($sku->id);

    expect($result['tier'])->toBe('category');
    expect(collect($result['skus'])->pluck('id')->all())->toBe([$peerSku->id]);
});

test('similarSkus returns a null tier and an empty list when no peer exists at all', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $sku = Sku::factory()->create(['product_id' => $product->id]);

    $result = ProductRelationshipFacade::similarSkus($sku->id);

    expect($result['tier'])->toBeNull();
    expect($result['skus'])->toBe([]);
});

test('similarSkus prefers category and size over plain category', function () {
    $category = Category::factory()->create();
    $sizeAttribute = Attribute::factory()->create(['forecast_relevant' => true]);
    $size42 = AttributeValue::factory()->create(['attribute_id' => $sizeAttribute->id, 'value' => '42']);

    $product = Product::factory()->create(['category_id' => $category->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $variant->attributeValues()->attach($size42->id, ['attribute_id' => $sizeAttribute->id]);
    $sku = Sku::factory()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id]);

    $sameSizeProduct = Product::factory()->create(['category_id' => $category->id]);
    $sameSizeVariant = ProductVariant::factory()->create(['product_id' => $sameSizeProduct->id]);
    $sameSizeVariant->attributeValues()->attach($size42->id, ['attribute_id' => $sizeAttribute->id]);
    $sameSizeSku = Sku::factory()->create(['product_id' => $sameSizeProduct->id, 'product_variant_id' => $sameSizeVariant->id]);

    $plainCategoryProduct = Product::factory()->create(['category_id' => $category->id]);
    Sku::factory()->create(['product_id' => $plainCategoryProduct->id]);

    $result = ProductRelationshipFacade::similarSkus($sku->id);

    expect($result['tier'])->toBe('category_size');
    expect(collect($result['skus'])->pluck('id')->all())->toBe([$sameSizeSku->id]);
});

test('cannibalizationCandidates flags a same-category pair with perfectly inverse daily demand', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();

    $productA = Product::factory()->create(['category_id' => $category->id]);
    $skuA = Sku::factory()->create(['product_id' => $productA->id]);

    $productB = Product::factory()->create(['category_id' => $category->id]);
    $skuB = Sku::factory()->create(['product_id' => $productB->id]);

    for ($day = 1; $day <= 20; $day++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $skuA->id,
            'snapshot_date' => now()->subDays($day),
            'sold_qty' => $day,
        ]);
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $skuB->id,
            'snapshot_date' => now()->subDays($day),
            'sold_qty' => 21 - $day,
        ]);
    }

    $rows = ProductRelationshipFacade::cannibalizationCandidates();

    expect($rows->total())->toBe(1);
    $row = $rows->items()[0];
    expect([$row->sku_a_id, $row->sku_b_id])->toEqualCanonicalizing([$skuA->id, $skuB->id]);
    expect($row->correlation)->toBe(-1.0);
});

test('cannibalizationCandidates does not flag a positively correlated pair', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();

    $productA = Product::factory()->create(['category_id' => $category->id]);
    $skuA = Sku::factory()->create(['product_id' => $productA->id]);

    $productB = Product::factory()->create(['category_id' => $category->id]);
    $skuB = Sku::factory()->create(['product_id' => $productB->id]);

    for ($day = 1; $day <= 20; $day++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $skuA->id,
            'snapshot_date' => now()->subDays($day),
            'sold_qty' => $day,
        ]);
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $skuB->id,
            'snapshot_date' => now()->subDays($day),
            'sold_qty' => $day,
        ]);
    }

    $rows = ProductRelationshipFacade::cannibalizationCandidates();

    expect($rows->total())->toBe(0);
});

test('successorCandidates pairs a declining product with a new product in the same category and brand', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();

    $decliningProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $decliningSku = Sku::factory()->create(['product_id' => $decliningProduct->id]);

    StockMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $decliningSku->id,
        'movement_type' => MovementType::Sale,
        'occurred_at' => now()->subDays(200),
    ]);

    for ($i = 31; $i <= 60; $i++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $decliningSku->id,
            'snapshot_date' => now()->subDays($i),
            'sold_qty' => 10,
        ]);
    }
    for ($i = 1; $i <= 30; $i++) {
        InventoryDailySnapshot::factory()->create([
            'warehouse_id' => $warehouse->id,
            'sku_id' => $decliningSku->id,
            'snapshot_date' => now()->subDays($i),
            'sold_qty' => 1,
        ]);
    }

    // Same category and brand, no sales at all yet -> Cold-start.
    $newProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $newSku = Sku::factory()->create(['product_id' => $newProduct->id]);

    // Same category, different brand -> must not be paired.
    $otherBrandProduct = Product::factory()->create(['category_id' => $category->id]);
    Sku::factory()->create(['product_id' => $otherBrandProduct->id]);

    $rows = ProductRelationshipFacade::successorCandidates();

    expect($rows->total())->toBe(1);
    $row = $rows->items()[0];
    expect($row->declining_sku_id)->toBe($decliningSku->id);
    expect($row->successor_sku_id)->toBe($newSku->id);
});
