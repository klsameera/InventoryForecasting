<?php

use App\Enums\ForecastSource;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryDailySnapshot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\Warehouse;
use Domain\Facades\DemandProfileFacade\DemandProfileFacade;
use Domain\Services\MlServiceClient\MlServiceClient;

test('returns a zero cold-start series when no peer has any history', function () {
    $warehouse = Warehouse::factory()->create();
    $sku = Sku::factory()->create();

    $result = DemandProfileFacade::fallbackSeries($warehouse->id, $sku->id, 5);

    expect($result['source'])->toBe(ForecastSource::ColdStart);
    expect($result['daily_sold_qty'])->toBe([0.0, 0.0, 0.0, 0.0, 0.0]);
});

test('falls back to category level when a peer sku in the same category has history', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();

    $coldSkuProduct = Product::factory()->create(['category_id' => $category->id]);
    $coldSku = Sku::factory()->create(['product_id' => $coldSkuProduct->id]);

    $peerProduct = Product::factory()->create(['category_id' => $category->id]);
    $peerSku = Sku::factory()->create(['product_id' => $peerProduct->id]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $peerSku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 10,
    ]);

    $result = DemandProfileFacade::fallbackSeries($warehouse->id, $coldSku->id, 5);

    expect($result['source'])->toBe(ForecastSource::Category);
    expect(array_sum($result['daily_sold_qty']))->toBe(10.0);
});

test('excludes the sku itself from its own peer aggregate', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $sku = Sku::factory()->create(['product_id' => $product->id]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 999,
    ]);

    $result = DemandProfileFacade::fallbackSeries($warehouse->id, $sku->id, 5);

    expect($result['source'])->toBe(ForecastSource::ColdStart);
    expect(array_sum($result['daily_sold_qty']))->toBe(0.0);
});

test('prefers brand and category over plain category when both have peer history', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();

    $coldSkuProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $coldSku = Sku::factory()->create(['product_id' => $coldSkuProduct->id]);

    $sameBrandProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $sameBrandSku = Sku::factory()->create(['product_id' => $sameBrandProduct->id]);

    $otherBrandProduct = Product::factory()->create(['category_id' => $category->id]);
    $otherBrandSku = Sku::factory()->create(['product_id' => $otherBrandProduct->id]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sameBrandSku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 8,
    ]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $otherBrandSku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 40,
    ]);

    $result = DemandProfileFacade::fallbackSeries($warehouse->id, $coldSku->id, 5);

    expect($result['source'])->toBe(ForecastSource::BrandCategory);
    expect(array_sum($result['daily_sold_qty']))->toBe(8.0);
});

test('prefers category and size over brand and category when a forecast-relevant attribute matches', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();
    $sizeAttribute = Attribute::factory()->create(['forecast_relevant' => true]);
    $size42 = AttributeValue::factory()->create(['attribute_id' => $sizeAttribute->id, 'value' => '42']);

    $coldVariantProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $coldVariant = ProductVariant::factory()->create(['product_id' => $coldVariantProduct->id]);
    $coldVariant->attributeValues()->attach($size42->id, ['attribute_id' => $sizeAttribute->id]);
    $coldSku = Sku::factory()->create(['product_id' => $coldVariant->product_id, 'product_variant_id' => $coldVariant->id]);

    $sameSizeProduct = Product::factory()->create(['category_id' => $category->id]);
    $sameSizeVariant = ProductVariant::factory()->create(['product_id' => $sameSizeProduct->id]);
    $sameSizeVariant->attributeValues()->attach($size42->id, ['attribute_id' => $sizeAttribute->id]);
    $sameSizeSku = Sku::factory()->create(['product_id' => $sameSizeVariant->product_id, 'product_variant_id' => $sameSizeVariant->id]);

    $sameBrandProduct = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id]);
    $sameBrandSku = Sku::factory()->create(['product_id' => $sameBrandProduct->id]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sameSizeSku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 6,
    ]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sameBrandSku->id,
        'snapshot_date' => now()->subDay(),
        'sold_qty' => 90,
    ]);

    $result = DemandProfileFacade::fallbackSeries($warehouse->id, $coldSku->id, 5);

    expect($result['source'])->toBe(ForecastSource::CategorySize);
    expect(array_sum($result['daily_sold_qty']))->toBe(6.0);
});

test('the fallback series length always matches the requested window, aligned like the sku series', function () {
    $warehouse = Warehouse::factory()->create();
    $category = Category::factory()->create();
    $peerProduct = Product::factory()->create(['category_id' => $category->id]);
    $peerSku = Sku::factory()->create(['product_id' => $peerProduct->id]);
    $coldProduct = Product::factory()->create(['category_id' => $category->id]);
    $coldSku = Sku::factory()->create(['product_id' => $coldProduct->id]);

    InventoryDailySnapshot::factory()->create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $peerSku->id,
        'snapshot_date' => now()->subDays(10),
        'sold_qty' => 5,
    ]);

    $result = DemandProfileFacade::fallbackSeries($warehouse->id, $coldSku->id, MlServiceClient::HISTORY_DAYS);

    expect($result['daily_sold_qty'])->toHaveCount(MlServiceClient::HISTORY_DAYS);
});
