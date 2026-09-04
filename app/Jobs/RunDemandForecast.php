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

    /**
     * Half an hour, because a forecast batch is not a 60-second job and never
     * was — the default simply used to be enough.
     *
     * The run assembles 180 days of history plus covariates for every
     * (warehouse, SKU) pair in scope, then makes one batched call to the ML
     * service. That work scales with the pair count, which went from 966 to
     * **2,259** when the catalogue's demand history was widened, and will grow
     * again. Run 9 finished in 31 seconds; run 10 was killed at 60.
     *
     * **The failure mode is silent, which is why this is declared rather than
     * left to the worker's default.** A killed worker does not fail the job or
     * write to `failed_jobs` — the reservation simply expires, and the run sits
     * at `processing` with an empty `error_message` forever. Nothing surfaces.
     *
     * Note this governs the worker's own alarm. `queue:listen` additionally
     * kills its child process at *its* `--timeout`, which no job property can
     * extend; see the `dev` script in composer.json.
     */
    public int $timeout = 1800;

    public function __construct(private readonly int $forecastRunId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ForecastRunFacade::processRun($this->forecastRunId);
    }
}
