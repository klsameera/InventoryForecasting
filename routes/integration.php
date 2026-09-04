<?php

use App\Http\Controllers\BuyabansSyncController;
use Illuminate\Support\Facades\Route;

/**
 * The BuyAbans integration — this application's only source of catalog, stock
 * and demand data. Read-only history plus two triggers (probe and manual sync);
 * rows are otherwise written by the nightly schedule (app:sync-buyabans, see
 * routes/console.php). No permission middleware yet: no permission package is
 * installed, see .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/buyabans-sync')->group(function () {
        Route::get('/', [BuyabansSyncController::class, 'index'])->name('buyabans-sync.index');
        Route::post('/probe', [BuyabansSyncController::class, 'probe'])->name('buyabans-sync.probe');
        Route::post('/run', [BuyabansSyncController::class, 'sync'])->name('buyabans-sync.run');
    });
});
