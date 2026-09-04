<?php

use Illuminate\Support\Facades\Route;

/**
 * These routes are behind the `readonly` middleware in production: the BuyAbans
 * back office owns this data and a local edit is undone by the next sync. The
 * module itself — controller, validation, service, transactions — is still
 * live code and still worth testing, so this suite turns local writes on
 * explicitly. `ReadOnlyTest` covers the guard itself.
 */
test('guests are redirected to the login page', function () {
    $response = $this->get(route('inventory-batch.index'));
    $response->assertRedirect(route('login'));
});

test('there are no create, edit, update or delete routes for inventory batches', function () {
    expect(Route::has('inventory-batch.create'))->toBeFalse();
    expect(Route::has('inventory-batch.edit'))->toBeFalse();
    expect(Route::has('inventory-batch.update'))->toBeFalse();
    expect(Route::has('inventory-batch.delete'))->toBeFalse();
});
