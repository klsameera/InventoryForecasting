<?php

use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sku;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('product.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the product list', function () {
    $user = User::factory()->create();
    Product::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('product.index'));

    $response->assertOk();
});

test('a product can be created', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();

    $response = $this->actingAs($user)->post(route('product.store'), [
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Skechers Go Walk',
        'product_type' => ProductType::Configurable->value,
        'status' => true,
    ]);

    $response->assertRedirect(route('product.index'));
    $this->assertDatabaseHas('products', [
        'name' => 'Skechers Go Walk',
        'category_id' => $category->id,
        'product_type' => 'configurable',
    ]);
});

test('a product requires an existing category', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('product.store'), [
        'category_id' => 999,
        'name' => 'Skechers Go Walk',
        'product_type' => ProductType::Simple->value,
        'status' => true,
    ]);

    $response->assertSessionHasErrors('category_id');
});

test('a product can be updated', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->post(route('product.update', $product->id), [
        'category_id' => $product->category_id,
        'brand_id' => $product->brand_id,
        'name' => 'New Name',
        'product_type' => $product->product_type->value,
        'status' => true,
    ]);

    $response->assertRedirect(route('product.index'));
    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'New Name']);
});

test('a product with skus cannot be deleted', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Sku::factory()->create(['product_id' => $product->id]);

    $response = $this->actingAs($user)->delete(route('product.delete', $product->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
});

test('a product without skus can be soft deleted', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->delete(route('product.delete', $product->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('products', ['id' => $product->id]);
});
