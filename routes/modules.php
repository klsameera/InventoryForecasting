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
use Illuminate\Support\Facades\Route;

/**
 * Phase 1 foundation modules — catalog and inventory ledger. No permission
 * middleware yet: no permission package is installed, see
 * .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/warehouse')->group(function () {
        Route::get('/', [WarehouseController::class, 'index'])->name('warehouse.index');
        Route::get('/create', [WarehouseController::class, 'create'])->name('warehouse.create');
        Route::get('/all', [WarehouseController::class, 'all'])->name('warehouse.all');
        Route::get('/{id}/edit', [WarehouseController::class, 'edit'])->name('warehouse.edit');
        Route::get('/{id}/get', [WarehouseController::class, 'get'])->name('warehouse.get');
        Route::post('/store', [WarehouseController::class, 'store'])->name('warehouse.store');
        Route::post('/{id}/update', [WarehouseController::class, 'update'])->name('warehouse.update');
        Route::delete('/{id}/delete', [WarehouseController::class, 'delete'])->name('warehouse.delete');
    });

    Route::prefix('/brand')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->name('brand.index');
        Route::get('/create', [BrandController::class, 'create'])->name('brand.create');
        Route::get('/all', [BrandController::class, 'all'])->name('brand.all');
        Route::get('/{id}/edit', [BrandController::class, 'edit'])->name('brand.edit');
        Route::get('/{id}/get', [BrandController::class, 'get'])->name('brand.get');
        Route::post('/store', [BrandController::class, 'store'])->name('brand.store');
        Route::post('/{id}/update', [BrandController::class, 'update'])->name('brand.update');
        Route::delete('/{id}/delete', [BrandController::class, 'delete'])->name('brand.delete');
    });

    Route::prefix('/category')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->name('category.index');
        Route::get('/create', [CategoryController::class, 'create'])->name('category.create');
        Route::get('/all', [CategoryController::class, 'all'])->name('category.all');
        Route::get('/{id}/edit', [CategoryController::class, 'edit'])->name('category.edit');
        Route::get('/{id}/get', [CategoryController::class, 'get'])->name('category.get');
        Route::post('/store', [CategoryController::class, 'store'])->name('category.store');
        Route::post('/{id}/update', [CategoryController::class, 'update'])->name('category.update');
        Route::delete('/{id}/delete', [CategoryController::class, 'delete'])->name('category.delete');
    });

    Route::prefix('/attribute')->group(function () {
        Route::get('/', [AttributeController::class, 'index'])->name('attribute.index');
        Route::get('/create', [AttributeController::class, 'create'])->name('attribute.create');
        Route::get('/all', [AttributeController::class, 'all'])->name('attribute.all');
        Route::get('/{id}/edit', [AttributeController::class, 'edit'])->name('attribute.edit');
        Route::get('/{id}/get', [AttributeController::class, 'get'])->name('attribute.get');
        Route::post('/store', [AttributeController::class, 'store'])->name('attribute.store');
        Route::post('/{id}/update', [AttributeController::class, 'update'])->name('attribute.update');
        Route::delete('/{id}/delete', [AttributeController::class, 'delete'])->name('attribute.delete');
    });

    Route::prefix('/product')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('product.index');
        Route::get('/create', [ProductController::class, 'create'])->name('product.create');
        Route::get('/all', [ProductController::class, 'all'])->name('product.all');
        Route::get('/{id}/edit', [ProductController::class, 'edit'])->name('product.edit');
        Route::get('/{id}/get', [ProductController::class, 'get'])->name('product.get');
        Route::post('/store', [ProductController::class, 'store'])->name('product.store');
        Route::post('/{id}/update', [ProductController::class, 'update'])->name('product.update');
        Route::delete('/{id}/delete', [ProductController::class, 'delete'])->name('product.delete');
    });

    Route::prefix('/product-variant')->group(function () {
        Route::get('/', [ProductVariantController::class, 'index'])->name('product-variant.index');
        Route::get('/create', [ProductVariantController::class, 'create'])->name('product-variant.create');
        Route::get('/all', [ProductVariantController::class, 'all'])->name('product-variant.all');
        Route::get('/{id}/edit', [ProductVariantController::class, 'edit'])->name('product-variant.edit');
        Route::get('/{id}/get', [ProductVariantController::class, 'get'])->name('product-variant.get');
        Route::post('/store', [ProductVariantController::class, 'store'])->name('product-variant.store');
        Route::post('/{id}/update', [ProductVariantController::class, 'update'])->name('product-variant.update');
        Route::delete('/{id}/delete', [ProductVariantController::class, 'delete'])->name('product-variant.delete');
    });

    Route::prefix('/sku')->group(function () {
        Route::get('/', [SkuController::class, 'index'])->name('sku.index');
        Route::get('/create', [SkuController::class, 'create'])->name('sku.create');
        Route::get('/all', [SkuController::class, 'all'])->name('sku.all');
        Route::get('/{id}/edit', [SkuController::class, 'edit'])->name('sku.edit');
        Route::get('/{id}/get', [SkuController::class, 'get'])->name('sku.get');
        Route::post('/store', [SkuController::class, 'store'])->name('sku.store');
        Route::post('/{id}/update', [SkuController::class, 'update'])->name('sku.update');
        Route::delete('/{id}/delete', [SkuController::class, 'delete'])->name('sku.delete');
    });

    // Read-only — derived balances, no write routes. See InventoryController.
    Route::prefix('/inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/all', [InventoryController::class, 'all'])->name('inventory.all');
    });

    // Append-only ledger — index (view) + create/store (adjustment entry)
    // only. No edit, update or delete routes. See StockMovementController.
    Route::prefix('/stock-movement')->group(function () {
        Route::get('/', [StockMovementController::class, 'index'])->name('stock-movement.index');
        Route::get('/create', [StockMovementController::class, 'create'])->name('stock-movement.create');
        Route::get('/all', [StockMovementController::class, 'all'])->name('stock-movement.all');
        Route::post('/store', [StockMovementController::class, 'store'])->name('stock-movement.store');
    });
});
