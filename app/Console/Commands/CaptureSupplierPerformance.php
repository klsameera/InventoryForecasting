<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\SupplierPerformanceFacade\SupplierPerformanceFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:capture-supplier-performance {month? : Calendar month to capture (Y-m), defaults to last month}')]
#[Description('Capture real supplier lead-time/fill-rate/on-time performance for one calendar month (app_plan.md §86)')]
class CaptureSupplierPerformance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $periodStart = $this->argument('month')
            ? Carbon::createFromFormat('Y-m', (string) $this->argument('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $periodEnd = $periodStart->copy()->endOfMonth();

        $result = SupplierPerformanceFacade::capturePeriod($periodStart, $periodEnd);

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
