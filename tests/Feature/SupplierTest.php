<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('supplier.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the supplier list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('supplier.index'));

    $response->assertOk();
});

test('Supplier has no create, edit or write routes', function () {
    // This application displays this data; the BuyAbans back office owns it.
    // These route names are asserted absent rather than asserted forbidden:
    // the routes were removed, not disabled, so nothing dead is left behind.
    foreach (['create', 'edit', 'store', 'update', 'delete'] as $action) {
        expect(Route::has('supplier.'.$action))->toBeFalse();
    }
});
