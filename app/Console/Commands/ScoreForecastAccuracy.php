<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\ForecastFacade\ForecastFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:score-forecast-accuracy')]
#[Description('Score every forecast whose window has elapsed against actual sales (app_plan.md §39)')]
class ScoreForecastAccuracy extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $result = ForecastFacade::scoreAccuracy();

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
