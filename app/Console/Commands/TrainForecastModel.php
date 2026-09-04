<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\MlTrainingDataFacade\MlTrainingDataFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * The retraining cadence the neural models require to stay worth serving.
 *
 * This is not an optimisation. Backtesting across a full year
 * (docs/app_architecture.md §1p) found the TFT's advantage over the
 * statistical baselines is entirely conditional on the model being recent:
 * roughly +8% while fresh, −4% by four months, and still behind at seven. The
 * baselines cannot decay — they re-derive from the trailing 180 days on every
 * call — so a trained model left alone does not merely stop improving things,
 * it becomes the worse choice while continuing to return confident numbers.
 *
 * Exports the current dataset and re-runs `ml-service/training/train.py`
 * against it. Scheduled monthly in `routes/console.php`, comfortably inside
 * the ~4-month window where the margin turns negative.
 */
#[Signature('app:train-forecast-model
    {--skip-export : Reuse the existing training_data.csv instead of re-exporting}
    {--only= : Train just one model — deepar or tft}
    {--max-epochs=20 : Training epochs before stopping}
    {--patience=4 : Epochs without improvement before early stopping}
    {--holdout-days= : Days held out of training — pass 365 to leave a full seasonal cycle}
    {--timeout=21600 : Seconds to allow the Python process, default 6 hours}')]
#[Description('Re-export the modelling dataset and retrain the neural forecasting models')]
class TrainForecastModel extends Command
{
    /**
     * Training runs for hours on CPU, so the Python process streams its output
     * through to this command rather than being captured — a scheduled run
     * writes progress to the log as it goes instead of producing nothing until
     * it finishes or dies.
     */
    public function handle(): int
    {
        if (! $this->option('skip-export')) {
            $this->info('Exporting the modelling dataset ...');

            $export = MlTrainingDataFacade::export();

            if (! $export['success']) {
                $this->error($export['message']);

                return self::FAILURE;
            }

            $this->line('  '.$export['message']);
        }

        $serviceDirectory = base_path('ml-service');
        $python = $this->pythonBinary($serviceDirectory);

        if ($python === null) {
            $this->error("No Python interpreter found for {$serviceDirectory}. Create the venv first — see ml-service/README.md.");

            return self::FAILURE;
        }

        $arguments = [
            $python,
            '-m',
            'training.train',
            '--tft-loss',
            // The loss the year-long comparison settled on: a count likelihood
            // whose point prediction is a mean by construction. Quantile loss
            // predicts the median, and the median of ~71%-zero demand is zero.
            'negative_binomial',
            '--max-epochs',
            (string) $this->option('max-epochs'),
            '--patience',
            (string) $this->option('patience'),
        ];

        if (is_string($only = $this->option('only')) && $only !== '') {
            $arguments[] = '--only';
            $arguments[] = $only;
        }

        // Left unset, `train.py` uses its minimal two-horizon holdout, which is
        // all a three-year history could afford. The back office now holds four
        // years, so `--holdout-days=365` leaves a full seasonal cycle unseen —
        // the only way to compare a covariate-aware model against the baselines
        // over a period that actually contains festivals and promotions. A
        // model can only be scored honestly after its own training cutoff, so
        // this is a *training* decision; no evaluation split can recover it
        // afterwards.
        if (is_string($holdout = $this->option('holdout-days')) && $holdout !== '') {
            $arguments[] = '--holdout-days';
            $arguments[] = $holdout;
        }

        $this->info('Training — this takes hours on CPU.');
        $this->line('  '.implode(' ', $arguments));

        $process = new Process($arguments, $serviceDirectory, null, null, (float) $this->option('timeout'));

        $exitCode = $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if ($exitCode !== 0) {
            $this->error("Training failed with exit code {$exitCode}.");

            return self::FAILURE;
        }

        // The service caches which algorithms it can serve, and a run that has
        // just produced the first checkpoint would otherwise stay invisible for
        // the rest of that cache window.
        Cache::forget('ml.available_algorithms');

        $this->info('Training complete. Restart the ML service to load the new checkpoints.');

        return self::SUCCESS;
    }

    /**
     * Prefer the service's own virtualenv over whatever `python` is on PATH:
     * torch and pytorch-forecasting are installed there, and a system
     * interpreter would fail on the import with a confusing error.
     */
    private function pythonBinary(string $serviceDirectory): ?string
    {
        $candidates = [
            $serviceDirectory.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.'python.exe',
            $serviceDirectory.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
