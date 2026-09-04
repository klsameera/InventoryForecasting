<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\MlTrainingDataFacade\MlTrainingDataFacade;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:export-ml-training-data
    {--path= : Destination relative to storage/app/, defaults to ml/training_data.csv}
    {--source= : Where demand comes from — ledger (the local stock ledger) or buyabans (demand synced from the back office). Defaults to services.ml.demand_source}')]
#[Description('Export the full warehouse/SKU/day modelling dataset for the Python training pipeline in ml-service/training/')]
class ExportMlTrainingData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path');

        // Defaults to the configured source rather than a hard-coded one. A
        // literal default here silently overrode `services.ml.demand_source`,
        // so this command exported the ledger while the serving path read
        // synced demand — the exact train/serve mismatch that config exists to
        // prevent.
        $source = is_string($this->option('source')) && $this->option('source') !== ''
            ? (string) $this->option('source')
            : MlTrainingDataService::demandSource();

        if (! in_array($source, MlTrainingDataService::SOURCES, true)) {
            $this->error("Unknown source '{$source}'. Expected one of: ".implode(', ', MlTrainingDataService::SOURCES).'.');

            return self::FAILURE;
        }

        $result = MlTrainingDataFacade::export(is_string($path) && $path !== '' ? $path : null, $source);

        if ($source === MlTrainingDataService::SOURCE_BUYABANS) {
            // Said out loud every run, because the resulting checkpoint is
            // indistinguishable from a ledger-trained one once it is on disk.
            $this->warn(
                'The buyabans source carries real demand and real per-day prices, but no stock history: '
                .'opening/closing/available quantities repeat the current stock position and receipts, '
                .'adjustments and stockouts export as zero. A model trained here learns nothing about availability.'
            );
        }

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        if (isset($result['data'])) {
            $this->table(
                ['Rows', 'Series', 'First date', 'Last date'],
                [[
                    number_format($result['data']['rows']),
                    number_format($result['data']['series']),
                    $result['data']['first_date'] ?? '—',
                    $result['data']['last_date'] ?? '—',
                ]],
            );
        }

        return self::SUCCESS;
    }
}
