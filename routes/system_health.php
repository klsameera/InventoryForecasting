<?php

use App\Http\Controllers\SystemHealthController;
use Illuminate\Support\Facades\Route;

/**
 * System health — one read-only page. No migration or model behind it: it
 * reports on what already exists rather than owning anything. No permission
 * middleware yet, for the reason in .ai/rules/architecture.md.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system-health.index');
});
