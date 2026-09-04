<?php

use App\Http\Controllers\AttributeController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\SkuController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\WarehouseController;
use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Illuminate\Support\Facades\Route;

/**
 * Catalog, locations and the stock ledger — all read-only.
 *
 * This application forecasts demand; it does not author the data it forecasts
 * on. The BuyAbans back office is the system of record for every table behind
 * these routes, and it all arrives through the read-only sync
 * ({@see BuyabansSyncService}). There are
 * no create, edit, store, update or delete routes here — not disabled ones,
 * none — because a local edit would be silently undone by the next nightly
 * sync, which upserts every row it fetches.
 *
 * To change a product, a category or a warehouse, change it in the back office
 * and run a sync.
 *
 * No permission middleware yet: no permission package is installed, see
 * .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/warehouse')->group(function () {
        Route::get('/', [WarehouseController::class, 'index'])->name('warehouse.index');
        Route::get('/all', [WarehouseController::class, 'all'])->name('warehouse.all');
        Route::get('/{id}/get', [WarehouseController::class, 'get'])->name('warehouse.get');
    });

    Route::prefix('/brand')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->name('brand.index');
        Route::get('/all', [BrandController::class, 'all'])->name('brand.all');
        Route::get('/{id}/get', [BrandController::class, 'get'])->name('brand.get');
    });

    Route::prefix('/category')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->name('category.index');
        Route::get('/all', [CategoryController::class, 'all'])->name('category.all');
        Route::get('/{id}/get', [CategoryController::class, 'get'])->name('category.get');
    });

    Route::prefix('/attribute')->group(function () {
        Route::get('/', [AttributeController::class, 'index'])->name('attribute.index');
        Route::get('/all', [AttributeController::class, 'all'])->name('attribute.all');
        Route::get('/{id}/get', [AttributeController::class, 'get'])->name('attribute.get');
    });

    Route::prefix('/product')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('product.index');
        Route::get('/all', [ProductController::class, 'all'])->name('product.all');
        Route::get('/{id}/get', [ProductController::class, 'get'])->name('product.get');
    });

    Route::prefix('/product-variant')->group(function () {
        Route::get('/', [ProductVariantController::class, 'index'])->name('product-variant.index');
        Route::get('/all', [ProductVariantController::class, 'all'])->name('product-variant.all');
        Route::get('/{id}/get', [ProductVariantController::class, 'get'])->name('product-variant.get');
    });

    Route::prefix('/sku')->group(function () {
        Route::get('/', [SkuController::class, 'index'])->name('sku.index');
        Route::get('/all', [SkuController::class, 'all'])->name('sku.all');
        Route::get('/{id}/get', [SkuController::class, 'get'])->name('sku.get');
    });

    // Derived balances, read from the ledger.
    Route::prefix('/inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/all', [InventoryController::class, 'all'])->name('inventory.all');
    });

    // Append-only ledger, now append-only by history alone: nothing in this
    // application writes to it. See StockMovementController.
    Route::prefix('/stock-movement')->group(function () {
        Route::get('/', [StockMovementController::class, 'index'])->name('stock-movement.index');
        Route::get('/all', [StockMovementController::class, 'all'])->name('stock-movement.all');
    });
});
