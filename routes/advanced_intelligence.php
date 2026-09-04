<?php

use App\Http\Controllers\DemandInsightsController;
use App\Http\Controllers\PriceElasticityController;
use App\Http\Controllers\ProductRelationshipController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\SupplierPerformanceController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 10 — advanced intelligence (app_plan.md §86). Each concept below
 * extends an existing engine or is a small, focused read-only computed
 * report — no new architectural layers, matching every prior phase. No
 * permission middleware yet: no permission package is installed, see
 * .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/supplier-performance')->group(function () {
        Route::get('/', [SupplierPerformanceController::class, 'index'])->name('supplier-performance.index');
        Route::post('/capture', [SupplierPerformanceController::class, 'capture'])->name('supplier-performance.capture');
    });

    Route::prefix('/demand-insights')->group(function () {
        Route::get('/lost-sales', [DemandInsightsController::class, 'lostSales'])->name('demand-insights.lost-sales');
        Route::get('/anomalies', [DemandInsightsController::class, 'anomalies'])->name('demand-insights.anomalies');
    });

    Route::prefix('/product-relationship')->group(function () {
        Route::get('/similarity', [ProductRelationshipController::class, 'similarity'])->name('product-relationship.similarity');
        Route::get('/cannibalization', [ProductRelationshipController::class, 'cannibalization'])->name('product-relationship.cannibalization');
        Route::get('/successors', [ProductRelationshipController::class, 'successors'])->name('product-relationship.successors');
    });

    Route::get('/price-elasticity', [PriceElasticityController::class, 'index'])->name('price-elasticity.index');

    Route::prefix('/promotion')->group(function () {
        Route::get('/', [PromotionController::class, 'index'])->name('promotion.index');
        Route::get('/all', [PromotionController::class, 'all'])->name('promotion.all');
        Route::get('/{id}/get', [PromotionController::class, 'get'])->name('promotion.get');
        Route::get('/{id}/impact', [PromotionController::class, 'impact'])->name('promotion.impact');
    });
});
