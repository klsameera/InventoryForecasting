<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('product-variant.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the variant list', function () {
    $user = User::factory()->create();
    ProductVariant::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('product-variant.index'));

    $response->assertOk();
});

test('a variant can be created with attribute values', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $attribute = Attribute::factory()->create();
    $value = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);

    $response = $this->actingAs($user)->post(route('product-variant.store'), [
        'product_id' => $product->id,
        'name' => 'Size 42',
        'status' => true,
        'attribute_values' => [
            ['attribute_id' => $attribute->id, 'attribute_value_id' => $value->id],
        ],
    ]);

    $response->assertRedirect(route('product-variant.index'));
    $variant = ProductVariant::where('name', 'Size 42')->firstOrFail();
    expect($variant->attributeValues()->pluck('attribute_values.id')->all())->toBe([$value->id]);
});

test('updating a variant syncs its attribute values', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $attribute = Attribute::factory()->create();
    $oldValue = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);
    $newValue = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);
    $variant->attributeValues()->attach($oldValue->id, ['attribute_id' => $attribute->id]);

    $response = $this->actingAs($user)->post(route('product-variant.update', $variant->id), [
        'product_id' => $variant->product_id,
        'name' => $variant->name,
        'status' => true,
        'attribute_values' => [
            ['attribute_id' => $attribute->id, 'attribute_value_id' => $newValue->id],
        ],
    ]);

    $response->assertRedirect(route('product-variant.index'));
    expect($variant->attributeValues()->pluck('attribute_values.id')->all())->toBe([$newValue->id]);
});

test('a variant with skus cannot be deleted', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    Sku::factory()->create(['product_variant_id' => $variant->id]);

    $response = $this->actingAs($user)->delete(route('product-variant.delete', $variant->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'deleted_at' => null]);
});
