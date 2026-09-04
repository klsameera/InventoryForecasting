<?php

use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InventoryBatchController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

/**
 * Suppliers, purchasing, sales and transfers — read-only history.
 *
 * Stock is operated in the BuyAbans back office, not here. These modules keep
 * their listings so the history they already hold stays readable, and have no
 * create, edit or status-transition routes at all.
 *
 * No permission middleware yet: no permission package is installed, see
 * .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/supplier')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('supplier.index');
        Route::get('/all', [SupplierController::class, 'all'])->name('supplier.all');
        Route::get('/{id}/get', [SupplierController::class, 'get'])->name('supplier.get');
    });

    Route::prefix('/purchase-order')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('purchase-order.index');
        Route::get('/all', [PurchaseOrderController::class, 'all'])->name('purchase-order.all');
        Route::get('/{id}/get', [PurchaseOrderController::class, 'get'])->name('purchase-order.get');
    });

    Route::prefix('/goods-receipt')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('goods-receipt.index');
        Route::get('/all', [GoodsReceiptController::class, 'all'])->name('goods-receipt.all');
    });

    Route::prefix('/stock-transfer')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('stock-transfer.index');
        Route::get('/all', [StockTransferController::class, 'all'])->name('stock-transfer.all');
        Route::get('/{id}/get', [StockTransferController::class, 'get'])->name('stock-transfer.get');
    });

    Route::prefix('/sales-order')->group(function () {
        Route::get('/', [SalesOrderController::class, 'index'])->name('sales-order.index');
        Route::get('/all', [SalesOrderController::class, 'all'])->name('sales-order.all');
        Route::get('/{id}/get', [SalesOrderController::class, 'get'])->name('sales-order.get');
    });

    Route::prefix('/sales-return')->group(function () {
        Route::get('/', [SalesReturnController::class, 'index'])->name('sales-return.index');
        Route::get('/all', [SalesReturnController::class, 'all'])->name('sales-return.all');
    });

    // Read-only FIFO lot ledger.
    Route::prefix('/inventory-batch')->group(function () {
        Route::get('/', [InventoryBatchController::class, 'index'])->name('inventory-batch.index');
        Route::get('/all', [InventoryBatchController::class, 'all'])->name('inventory-batch.all');
    });
});
