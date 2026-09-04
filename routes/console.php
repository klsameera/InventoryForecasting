<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Everything this application forecasts on comes from the BuyAbans back
// office, so the sync runs before any of the jobs that read that data.
// Incremental by default: a 14-day window re-pulls recent days (orders get
// cancelled and refunded after the fact, which changes demand retroactively)
// without re-downloading three years every night.
Schedule::command('app:sync-buyabans all --days=14')
    ->dailyAt('00:05')
    ->withoutOverlapping();

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
