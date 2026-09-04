<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * These routes are behind the `readonly` middleware in production: the BuyAbans
 * back office owns this data and a local edit is undone by the next sync. The
 * module itself — controller, validation, service, transactions — is still
 * live code and still worth testing, so this suite turns local writes on
 * explicitly. `ReadOnlyTest` covers the guard itself.
 */
test('guests are redirected to the login page', function () {
    $response = $this->get(route('purchase-order.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the purchase order list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('purchase-order.index'));

    $response->assertOk();
});

test('PurchaseOrder has no create, edit or write routes', function () {
    // This application displays this data; the BuyAbans back office owns it.
    // These route names are asserted absent rather than asserted forbidden:
    // the routes were removed, not disabled, so nothing dead is left behind.
    foreach (['create', 'edit', 'store', 'update', 'delete'] as $action) {
        expect(Route::has('purchase-order.'.$action))->toBeFalse();
    }
});
