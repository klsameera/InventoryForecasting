<?php

use App\Http\Controllers\InventoryAnalyticsController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 3 — deterministic inventory analytics. Read-only: index only, no
 * migration/model behind it. No permission middleware yet: no permission
 * package is installed, see .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/inventory-analytics', [InventoryAnalyticsController::class, 'index'])->name('inventory-analytics.index');
});
