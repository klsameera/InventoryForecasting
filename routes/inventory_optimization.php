<?php

use App\Http\Controllers\InventoryRecommendationController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 7 — inventory optimization (app_plan.md §83). Read-only listing plus
 * the engine trigger (`generate`) and the four human-decision actions
 * (`review`/`accept`/`modify`/`reject`, app_plan.md §53). No
 * create/edit/update/delete — see InventoryRecommendationController's
 * docblock. No permission middleware yet: no permission package is
 * installed, see .ai/rules/architecture.md.
 *
 * `central-allocation` (Phase 9, app_plan.md §85) is a second, separate
 * read-only route on the same controller — a cross-warehouse rollup, not a
 * new module.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/inventory-recommendation')->group(function () {
        Route::get('/', [InventoryRecommendationController::class, 'index'])->name('inventory-recommendation.index');
        Route::get('/central-allocation', [InventoryRecommendationController::class, 'centralAllocation'])->name('inventory-recommendation.central-allocation');
        Route::post('/generate', [InventoryRecommendationController::class, 'generate'])->name('inventory-recommendation.generate');
        Route::post('/{id}/review', [InventoryRecommendationController::class, 'review'])->name('inventory-recommendation.review');
        Route::post('/{id}/accept', [InventoryRecommendationController::class, 'accept'])->name('inventory-recommendation.accept');
        Route::post('/{id}/modify', [InventoryRecommendationController::class, 'modify'])->name('inventory-recommendation.modify');
        Route::post('/{id}/reject', [InventoryRecommendationController::class, 'reject'])->name('inventory-recommendation.reject');
    });
});
