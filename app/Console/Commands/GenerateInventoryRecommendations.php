<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\InventoryRecommendationFacade\InventoryRecommendationFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:generate-inventory-recommendations')]
#[Description('Re-run the inventory decision engine over every warehouse/SKU pair with a forecast (app_plan.md §40, §62)')]
class GenerateInventoryRecommendations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $result = InventoryRecommendationFacade::generate();

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
