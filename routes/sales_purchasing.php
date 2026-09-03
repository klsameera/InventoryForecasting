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
 * Phase 2 — Sales and purchasing. No permission middleware yet: no
 * permission package is installed, see .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/supplier')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('supplier.index');
        Route::get('/create', [SupplierController::class, 'create'])->name('supplier.create');
        Route::get('/all', [SupplierController::class, 'all'])->name('supplier.all');
        Route::get('/{id}/edit', [SupplierController::class, 'edit'])->name('supplier.edit');
        Route::get('/{id}/get', [SupplierController::class, 'get'])->name('supplier.get');
        Route::post('/store', [SupplierController::class, 'store'])->name('supplier.store');
        Route::post('/{id}/update', [SupplierController::class, 'update'])->name('supplier.update');
        Route::delete('/{id}/delete', [SupplierController::class, 'delete'])->name('supplier.delete');
    });

    Route::prefix('/purchase-order')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('purchase-order.index');
        Route::get('/create', [PurchaseOrderController::class, 'create'])->name('purchase-order.create');
        Route::get('/all', [PurchaseOrderController::class, 'all'])->name('purchase-order.all');
        Route::get('/{id}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-order.edit');
        Route::get('/{id}/get', [PurchaseOrderController::class, 'get'])->name('purchase-order.get');
        Route::post('/store', [PurchaseOrderController::class, 'store'])->name('purchase-order.store');
        Route::post('/{id}/update', [PurchaseOrderController::class, 'update'])->name('purchase-order.update');
        Route::post('/{id}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-order.approve');
        Route::post('/{id}/mark-ordered', [PurchaseOrderController::class, 'markOrdered'])->name('purchase-order.mark-ordered');
        Route::post('/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-order.cancel');
        Route::delete('/{id}/delete', [PurchaseOrderController::class, 'delete'])->name('purchase-order.delete');
    });

    Route::prefix('/goods-receipt')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('goods-receipt.index');
        Route::get('/create', [GoodsReceiptController::class, 'create'])->name('goods-receipt.create');
        Route::get('/all', [GoodsReceiptController::class, 'all'])->name('goods-receipt.all');
        Route::post('/store', [GoodsReceiptController::class, 'store'])->name('goods-receipt.store');
    });

    Route::prefix('/stock-transfer')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('stock-transfer.index');
        Route::get('/create', [StockTransferController::class, 'create'])->name('stock-transfer.create');
        Route::get('/all', [StockTransferController::class, 'all'])->name('stock-transfer.all');
        Route::get('/{id}/edit', [StockTransferController::class, 'edit'])->name('stock-transfer.edit');
        Route::get('/{id}/get', [StockTransferController::class, 'get'])->name('stock-transfer.get');
        Route::post('/store', [StockTransferController::class, 'store'])->name('stock-transfer.store');
        Route::post('/{id}/update', [StockTransferController::class, 'update'])->name('stock-transfer.update');
        Route::post('/{id}/approve', [StockTransferController::class, 'approve'])->name('stock-transfer.approve');
        Route::post('/{id}/dispatch', [StockTransferController::class, 'dispatch'])->name('stock-transfer.dispatch');
        Route::post('/{id}/receive', [StockTransferController::class, 'receive'])->name('stock-transfer.receive');
        Route::post('/{id}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfer.cancel');
        Route::delete('/{id}/delete', [StockTransferController::class, 'delete'])->name('stock-transfer.delete');
    });

    Route::prefix('/sales-order')->group(function () {
        Route::get('/', [SalesOrderController::class, 'index'])->name('sales-order.index');
        Route::get('/create', [SalesOrderController::class, 'create'])->name('sales-order.create');
        Route::get('/all', [SalesOrderController::class, 'all'])->name('sales-order.all');
        Route::get('/{id}/edit', [SalesOrderController::class, 'edit'])->name('sales-order.edit');
        Route::get('/{id}/get', [SalesOrderController::class, 'get'])->name('sales-order.get');
        Route::post('/store', [SalesOrderController::class, 'store'])->name('sales-order.store');
        Route::post('/{id}/update', [SalesOrderController::class, 'update'])->name('sales-order.update');
        Route::post('/{id}/confirm', [SalesOrderController::class, 'confirm'])->name('sales-order.confirm');
        Route::post('/{id}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-order.cancel');
        Route::delete('/{id}/delete', [SalesOrderController::class, 'delete'])->name('sales-order.delete');
    });

    Route::prefix('/sales-return')->group(function () {
        Route::get('/', [SalesReturnController::class, 'index'])->name('sales-return.index');
        Route::get('/create', [SalesReturnController::class, 'create'])->name('sales-return.create');
        Route::get('/all', [SalesReturnController::class, 'all'])->name('sales-return.all');
        Route::post('/store', [SalesReturnController::class, 'store'])->name('sales-return.store');
    });

    Route::prefix('/inventory-batch')->group(function () {
        Route::get('/', [InventoryBatchController::class, 'index'])->name('inventory-batch.index');
        Route::get('/all', [InventoryBatchController::class, 'all'])->name('inventory-batch.all');
    });
});
