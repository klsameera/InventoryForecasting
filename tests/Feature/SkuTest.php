<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sku;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('sku.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the sku list', function () {
    $user = User::factory()->create();
    Sku::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('sku.index'));

    $response->assertOk();
});

test('a sku can be created', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->post(route('sku.store'), [
        'product_id' => $product->id,
        'sku' => 'SK-001',
        'cost_price' => 10,
        'selling_price' => 20,
        'status' => true,
    ]);

    $response->assertRedirect(route('sku.index'));
    $this->assertDatabaseHas('skus', ['sku' => 'SK-001', 'product_id' => $product->id]);
});

test('a sku code must be unique', function () {
    $user = User::factory()->create();
    Sku::factory()->create(['sku' => 'SK-001']);
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->post(route('sku.store'), [
        'product_id' => $product->id,
        'sku' => 'SK-001',
        'cost_price' => 10,
        'selling_price' => 20,
        'status' => true,
    ]);

    $response->assertSessionHasErrors('sku');
});

test('a sku can be updated', function () {
    $user = User::factory()->create();
    $sku = Sku::factory()->create(['selling_price' => 20]);

    $response = $this->actingAs($user)->post(route('sku.update', $sku->id), [
        'product_id' => $sku->product_id,
        'sku' => $sku->sku,
        'cost_price' => $sku->cost_price,
        'selling_price' => 25,
        'status' => true,
    ]);

    $response->assertRedirect(route('sku.index'));
    $this->assertDatabaseHas('skus', ['id' => $sku->id, 'selling_price' => 25]);
});

test('a sku with stock on hand cannot be deleted', function () {
    $user = User::factory()->create();
    $sku = Sku::factory()->create();
    Inventory::factory()->create(['sku_id' => $sku->id, 'on_hand_qty' => 5]);

    $response = $this->actingAs($user)->delete(route('sku.delete', $sku->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('skus', ['id' => $sku->id, 'deleted_at' => null]);
});

test('a sku without stock can be soft deleted', function () {
    $user = User::factory()->create();
    $sku = Sku::factory()->create();

    $response = $this->actingAs($user)->delete(route('sku.delete', $sku->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('skus', ['id' => $sku->id]);
});
