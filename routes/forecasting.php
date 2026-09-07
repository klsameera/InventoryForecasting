<?php

use App\Http\Controllers\ForecastController;
use App\Http\Controllers\ForecastRunController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 5 scaffold — see ml-service/README.md and
 * domain/Services/MlServiceClient/MlServiceClient.php's docblock for what
 * "scaffold" means here. No permission middleware yet: no permission
 * package is installed, see .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('/forecast-run')->group(function () {
        Route::get('/', [ForecastRunController::class, 'index'])->name('forecast-run.index');
        Route::get('/create', [ForecastRunController::class, 'create'])->name('forecast-run.create');
        Route::post('/store', [ForecastRunController::class, 'store'])->name('forecast-run.store');
        Route::post('/{id}/retry', [ForecastRunController::class, 'retry'])->name('forecast-run.retry');
    });

    Route::prefix('/forecast')->group(function () {
        Route::get('/', [ForecastController::class, 'index'])->name('forecast.index');
        Route::post('/score-accuracy', [ForecastController::class, 'scoreAccuracy'])->name('forecast.score-accuracy');
    });
});
