<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\MlServiceClientFacade\MlServiceClientFacade;
use Domain\Facades\ModelSelectionFacade\ModelSelectionFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Answers "is the trained model actually being used?" without reading logs.
 *
 * Worth having as a command because the answer has three independent parts
 * that can each be wrong on their own: the service has to be reachable, it has
 * to have a loadable checkpoint, and `ML_DEFAULT_ALGORITHM` has to name it.
 * A forecast run with all three misaligned still succeeds — it quietly returns
 * baseline numbers — so nothing fails loudly enough to notice.
 */
#[Signature('app:forecast-model-status')]
#[Description('Report which forecasting algorithms the ML service can serve, and which one this app will use')]
class ForecastModelStatus extends Command
{
    public function handle(): int
    {
        $preferred = ModelSelectionFacade::preferredAlgorithm();
        $configured = (string) config('services.ml.default_algorithm');

        $this->line('ML service: '.config('services.ml.url'));

        try {
            $available = MlServiceClientFacade::availableAlgorithms();
        } catch (Throwable $exception) {
            $this->error('Unreachable — '.$exception->getMessage());
            $this->line('Forecast runs will fail until the service is up. Start it with:');
            $this->line('  cd ml-service && .venv/Scripts/uvicorn app.main:app --port 8090');

            return self::FAILURE;
        }

        $rows = [];

        foreach (['ewma', 'seasonal_naive', 'tft', 'deepar'] as $algorithm) {
            $servable = in_array($algorithm, $available, true);

            $rows[] = [
                $algorithm,
                $servable ? 'yes' : 'no',
                in_array($algorithm, ['tft', 'deepar'], true) ? 'trained' : 'statistical',
                $algorithm === $preferred ? '<- default for new SKUs' : '',
            ];
        }

        $this->table(['Algorithm', 'Servable', 'Kind', ''], $rows);

        if ($configured !== $preferred) {
            $this->warn("ML_DEFAULT_ALGORITHM is '{$configured}', which is not a known algorithm — falling back to '{$preferred}'.");
        }

        if (! in_array($preferred, $available, true)) {
            $this->warn("The default algorithm '{$preferred}' is not servable right now. Runs will fall back to a baseline and record it as such.");

            return self::FAILURE;
        }

        // Stated plainly because it is the question this command exists to
        // answer, and a table of "yes" rows does not answer it on its own: an
        // available checkpoint that nothing selects is not in use.
        $this->info(
            in_array($preferred, ['tft', 'deepar'], true)
                ? "Trained model '{$preferred}' is in use for Established SKUs with no accuracy history yet."
                : "Serving the '{$preferred}' baseline. Set ML_DEFAULT_ALGORITHM=tft to use the trained model."
        );

        $this->line('Per-SKU selection overrides this as soon as two algorithms have scored history for that SKU.');

        return self::SUCCESS;
    }
}
