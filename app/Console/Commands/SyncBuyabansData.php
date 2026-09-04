<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Domain\Facades\BuyabansSyncFacade\BuyabansSyncFacade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('app:sync-buyabans
    {stage=all : Which stage to run — all, locations, categories, brands, products, stock or demand}
    {--days= : Days of demand history to pull (default: services.buyabans.history_days)}
    {--from= : Start date for the demand window (Y-m-d), overrides --days}
    {--to= : End date for the demand window (Y-m-d), defaults to today}
    {--grain= : Location grain for demand — warehouse, channel or national}
    {--probe : Report what the back office holds without syncing anything}')]
#[Description('Pull catalog, locations, stock and demand history from the BuyAbans back office')]
class SyncBuyabansData extends Command
{
    /** @var list<string> */
    private const STAGES = ['all', 'locations', 'categories', 'brands', 'products', 'stock', 'demand'];

    public function handle(): int
    {
        if ($this->option('probe')) {
            return $this->probe();
        }

        $stage = (string) $this->argument('stage');

        if (! in_array($stage, self::STAGES, true)) {
            $this->error("Unknown stage '{$stage}'. Expected one of: ".implode(', ', self::STAGES).'.');

            return self::FAILURE;
        }

        $options = array_filter([
            'days' => $this->option('days'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'grain' => $this->option('grain'),
        ], fn ($value) => $value !== null && $value !== '');

        $this->info("Syncing '{$stage}' from the BuyAbans back office...");

        $method = $stage === 'all' ? 'syncAll' : 'sync'.Str::studly($stage);
        $result = BuyabansSyncFacade::{$method}($options);

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);
        $this->renderResult($result['data'] ?? []);

        return self::SUCCESS;
    }

    /**
     * Reports the back office's own counts — the fastest way to see whether
     * there is enough sales history there to be worth training on, before
     * committing to a multi-hour pull.
     */
    private function probe(): int
    {
        $result = BuyabansSyncFacade::probe();

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $data = $result['data'] ?? [];
        $counts = $data['counts'] ?? [];

        $this->info('Connected to '.config('services.buyabans.url'));
        $this->newLine();

        $this->table(
            ['Resource', 'Count'],
            collect($counts)->map(fn ($value, $key) => [$key, number_format((float) $value)])->values()->all()
        );

        $this->line('Orders span:  '.($data['orders']['first_order_at'] ?? '—').' to '.($data['orders']['last_order_at'] ?? '—'));
        $this->line('Demand spans: '.($data['demand']['first_order_at'] ?? '—').' to '.($data['demand']['last_order_at'] ?? '—'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderResult(array $data): void
    {
        // syncAll returns a map of stage => result; a single stage returns one
        // result directly. Normalise so both render the same table.
        $stages = isset($data['stage']) ? ['' => ['data' => $data]] : $data;
        $rows = [];

        foreach ($stages as $name => $result) {
            $payload = $result['data'] ?? [];

            $rows[] = [
                $payload['stage'] ?? $name,
                number_format((float) ($payload['fetched'] ?? 0)),
                number_format((float) ($payload['written'] ?? 0)),
                $payload['pages'] ?? 0,
                json_encode($payload['summary'] ?? null),
            ];
        }

        $this->table(['Stage', 'Fetched', 'Written', 'Pages', 'Notes'], $rows);
    }
}
