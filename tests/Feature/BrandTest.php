<?php

use App\Models\Brand;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('brand.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the brand list', function () {
    $user = User::factory()->create();
    Brand::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('brand.index'));

    $response->assertOk();
});

test('a brand can be created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('brand.store'), [
        'name' => 'Skechers',
        'code' => 'BR-001',
        'status' => true,
    ]);

    $response->assertRedirect(route('brand.index'));
    $this->assertDatabaseHas('brands', ['name' => 'Skechers', 'code' => 'BR-001']);
});

test('a brand requires a unique code', function () {
    $user = User::factory()->create();
    Brand::factory()->create(['code' => 'BR-001']);

    $response = $this->actingAs($user)->post(route('brand.store'), [
        'name' => 'Another Brand',
        'code' => 'BR-001',
        'status' => true,
    ]);

    $response->assertSessionHasErrors('code');
});

test('a brand can be updated', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->post(route('brand.update', $brand->id), [
        'name' => 'New Name',
        'code' => $brand->code,
        'status' => true,
    ]);

    $response->assertRedirect(route('brand.index'));
    $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'New Name']);
});

test('a brand can be soft deleted', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->create();

    $response = $this->actingAs($user)->delete(route('brand.delete', $brand->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('brands', ['id' => $brand->id]);
});
