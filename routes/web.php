<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/modules.php';
require __DIR__.'/sales_purchasing.php';
require __DIR__.'/analytics.php';
require __DIR__.'/pipeline.php';
require __DIR__.'/forecasting.php';
require __DIR__.'/inventory_optimization.php';
require __DIR__.'/advanced_intelligence.php';
