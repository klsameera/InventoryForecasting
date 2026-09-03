<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\MlTrainingDataFacade\MlTrainingDataFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:export-ml-training-data {--path= : Destination relative to storage/app/, defaults to ml/training_data.csv}')]
#[Description('Export the full warehouse/SKU/day modelling dataset for the Python training pipeline in ml-service/training/')]
class ExportMlTrainingData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path');

        $result = MlTrainingDataFacade::export(is_string($path) && $path !== '' ? $path : null);

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
