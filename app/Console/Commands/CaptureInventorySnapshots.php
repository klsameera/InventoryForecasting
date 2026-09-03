<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\InventoryDailySnapshotFacade\InventoryDailySnapshotFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:capture-inventory-snapshots {date? : Calendar date to capture (Y-m-d), defaults to yesterday}')]
#[Description('Capture the inventory daily snapshot (opening/closing balances, demand, stockout minutes) for one calendar date')]
class CaptureInventorySnapshots extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : now()->subDay();

        $result = InventoryDailySnapshotFacade::captureDay($date);

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
