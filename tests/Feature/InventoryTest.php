<?php

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('inventory.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view inventory balances', function () {
    $user = User::factory()->create();
    Inventory::factory()->create(['on_hand_qty' => 50, 'available_qty' => 50]);

    $response = $this->actingAs($user)->get(route('inventory.index'));

    $response->assertOk();
});

test('there are no write routes for inventory balances', function () {
    expect(Route::has('inventory.store'))->toBeFalse();
    expect(Route::has('inventory.update'))->toBeFalse();
    expect(Route::has('inventory.delete'))->toBeFalse();
});
