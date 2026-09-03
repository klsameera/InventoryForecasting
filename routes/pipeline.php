<?php

use App\Http\Controllers\InventoryDailySnapshotController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 4 — the data pipeline. Read-only history plus a manual capture
 * trigger; rows are otherwise written by the nightly schedule
 * (app:capture-inventory-snapshots, see routes/console.php). No permission
 * middleware yet: no permission package is installed, see
 * .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/inventory-daily-snapshot')->group(function () {
        Route::get('/', [InventoryDailySnapshotController::class, 'index'])->name('inventory-daily-snapshot.index');
        Route::post('/capture', [InventoryDailySnapshotController::class, 'capture'])->name('inventory-daily-snapshot.capture');
    });
});
