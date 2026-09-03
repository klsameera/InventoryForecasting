<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:capture-inventory-snapshots')
    ->dailyAt('00:15')
    ->withoutOverlapping();

Schedule::command('app:score-forecast-accuracy')
    ->dailyAt('00:30')
    ->withoutOverlapping();

Schedule::command('app:generate-inventory-recommendations')
    ->dailyAt('00:45')
    ->withoutOverlapping();

Schedule::command('app:capture-supplier-performance')
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping();

// The neural models' accuracy advantage decays with the age of their training
// data — roughly +8% over the baselines while fresh, negative by four months
// (docs/app_architecture.md §1p). Monthly keeps the served checkpoint well
// inside that window. Runs on the 1st after supplier performance, on a Sunday
// night slot because it occupies the CPU for hours.
Schedule::command('app:train-forecast-model')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->runInBackground();
