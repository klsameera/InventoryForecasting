<?php

use App\Models\User;
use App\Models\Warehouse;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('warehouse.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the warehouse list', function () {
    $user = User::factory()->create();
    Warehouse::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('warehouse.index'));

    $response->assertOk();
});

test('a warehouse can be created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('warehouse.store'), [
        'name' => 'Colombo Central',
        'code' => 'WH-001',
        'address' => '123 Galle Road',
        'status' => true,
    ]);

    $response->assertRedirect(route('warehouse.index'));
    $this->assertDatabaseHas('warehouses', [
        'name' => 'Colombo Central',
        'code' => 'WH-001',
    ]);
});

test('a warehouse requires a unique code', function () {
    $user = User::factory()->create();
    Warehouse::factory()->create(['code' => 'WH-001']);

    $response = $this->actingAs($user)->post(route('warehouse.store'), [
        'name' => 'Kandy Branch',
        'code' => 'WH-001',
        'status' => true,
    ]);

    $response->assertSessionHasErrors('code');
});

test('a warehouse can be updated', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->post(route('warehouse.update', $warehouse->id), [
        'name' => 'New Name',
        'code' => $warehouse->code,
        'status' => true,
    ]);

    $response->assertRedirect(route('warehouse.index'));
    $this->assertDatabaseHas('warehouses', [
        'id' => $warehouse->id,
        'name' => 'New Name',
    ]);
});

test('a warehouse can be soft deleted', function () {
    $user = User::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $response = $this->actingAs($user)->delete(route('warehouse.delete', $warehouse->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
});
