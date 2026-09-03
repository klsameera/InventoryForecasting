<?php

declare(strict_types=1);

namespace App\Jobs;

use Domain\Facades\ForecastRunFacade\ForecastRunFacade;
use Domain\Services\ForecastRunService\ForecastRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Thin dispatch wrapper — all the actual work is in
 * {@see ForecastRunService::processRun()},
 * matching the project's "business logic lives in domain/Services" rule.
 */
final class RunDemandForecast implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $forecastRunId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ForecastRunFacade::processRun($this->forecastRunId);
    }
}
