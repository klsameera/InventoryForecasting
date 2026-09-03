<?php

declare(strict_types=1);

namespace Domain\Services\DemandInsightsService;

use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 (app_plan.md §86) — two demand-side reads over the same
 * `inventory_daily_snapshots` history (Phase 4), computed on request like
 * {@see InventoryAnalyticsService}
 * (§1g): no migration, no model, nothing persisted.
 *
 * **Lost-sales estimation** and **demand anomaly detection** are grouped in
 * one Service rather than two, because both read the exact same base rows
 * and reason about the same "what is demand telling us that inventory
 * alone doesn't" question — not because they share a formula.
 */
final class DemandInsightsService
{
    /** Default lookback window when a filter doesn't specify one. */
    private const DEFAULT_LOOKBACK_DAYS = 30;

    /**
     * |z-score| at or above this flags a day as an anomaly — the
     * conventional "more than two standard deviations" threshold, not
     * calibrated against any real "was this actually unusual" outcome data
     * (there isn't any yet), the same epistemic honesty as every other
     * round-number threshold in this codebase.
     */
    private const ANOMALY_Z_SCORE_THRESHOLD = 2.0;

    /**
     * app_plan.md §86's "lost-sales estimation," built directly on Phase 4's
     * `stockout_minutes` — for each warehouse/SKU pair with at least one
     * stockout day in the window, estimates lost units as
     * `(stockout_minutes / 1440) × normal daily rate`, where the normal
     * rate is the pair's own average `sold_qty` over its **non-stockout**
     * days in the same window. A pair that was out of stock for the
     * *entire* window has no non-stockout days to establish a normal rate
     * from and is skipped — an honest "not enough data," not a guessed
     * rate borrowed from elsewhere.
     *
     * @param  array<string, mixed>  $filters
     */
    public function lostSales(array $filters = []): LengthAwarePaginator
    {
        $groups = $this->baseRows($filters)->groupBy(fn ($row) => "{$row->warehouse_id}:{$row->sku_id}");

        $rows = $groups->map(function (Collection $group) {
            $stockoutDays = $group->where('stockout_flag', true);

            if ($stockoutDays->isEmpty()) {
                return null;
            }

            $normalDays = $group->where('stockout_flag', false);

            if ($normalDays->isEmpty()) {
                return null;
            }

            $normalDailyRate = (float) $normalDays->avg('sold_qty');
            $lostUnits = $stockoutDays->sum(fn ($row) => ((int) $row->stockout_minutes / 1440) * $normalDailyRate);

            $first = $group->first();

            return (object) [
                'warehouse_id' => (int) $first->warehouse_id,
                'warehouse_name' => $first->warehouse_name,
                'sku_id' => (int) $first->sku_id,
                'sku' => $first->sku,
                'product_name' => $first->product_name,
                'stockout_days' => $stockoutDays->count(),
                'normal_daily_rate' => round($normalDailyRate, 2),
                'estimated_lost_units' => round($lostUnits, 2),
            ];
        })->filter()->values();

        $filtered = $this->applySearch($rows, $filters)->sortByDesc('estimated_lost_units')->values();

        return $this->paginate($filtered, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * app_plan.md §86's "demand anomaly detection" — flags individual days
     * where a pair's actual `sold_qty` was at least
     * {@see ANOMALY_Z_SCORE_THRESHOLD} standard deviations from that same
     * pair's own mean over the window (sample std-dev, matching
     * `InventoryRecommendationService::dynamicSafetyStock()`'s convention).
     * A pair needs at least two days of history and non-zero variability to
     * have a std-dev at all — otherwise it's skipped, never treated as
     * "zero variance means everything is an anomaly."
     *
     * @param  array<string, mixed>  $filters
     */
    public function anomalies(array $filters = []): LengthAwarePaginator
    {
        $groups = $this->baseRows($filters)->groupBy(fn ($row) => "{$row->warehouse_id}:{$row->sku_id}");

        $events = collect();

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $mean = (float) $group->avg('sold_qty');
            $variance = $group->sum(fn ($row) => ($row->sold_qty - $mean) ** 2) / ($group->count() - 1);
            $stdDev = sqrt($variance);

            if ($stdDev <= 0.0) {
                continue;
            }

            foreach ($group as $row) {
                $zScore = ($row->sold_qty - $mean) / $stdDev;

                if (abs($zScore) < self::ANOMALY_Z_SCORE_THRESHOLD) {
                    continue;
                }

                $events->push((object) [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'warehouse_name' => $row->warehouse_name,
                    'sku_id' => (int) $row->sku_id,
                    'sku' => $row->sku,
                    'product_name' => $row->product_name,
                    'snapshot_date' => $row->snapshot_date,
                    'actual_qty' => (int) $row->sold_qty,
                    'expected_qty' => round($mean, 2),
                    'z_score' => round($zScore, 2),
                ]);
            }
        }

        $filtered = $this->applySearch($events, $filters)
            ->sortByDesc(fn ($event) => abs($event->z_score))
            ->values();

        return $this->paginate($filtered, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function baseRows(array $filters): Collection
    {
        $lookbackDays = max(1, (int) ($filters['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS));
        $since = now()->subDays($lookbackDays)->toDateString();

        return DB::table('inventory_daily_snapshots')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_daily_snapshots.warehouse_id')
            ->join('skus', 'skus.id', '=', 'inventory_daily_snapshots.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereDate('inventory_daily_snapshots.snapshot_date', '>=', $since)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('inventory_daily_snapshots.warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('inventory_daily_snapshots.sku_id', $skuId))
            ->select([
                'inventory_daily_snapshots.warehouse_id',
                'inventory_daily_snapshots.sku_id',
                DB::raw('DATE(inventory_daily_snapshots.snapshot_date) as snapshot_date'),
                'inventory_daily_snapshots.sold_qty',
                'inventory_daily_snapshots.stockout_flag',
                'inventory_daily_snapshots.stockout_minutes',
                'warehouses.name as warehouse_name',
                'skus.sku',
                'products.name as product_name',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function applySearch(Collection $rows, array $filters): Collection
    {
        $search = $filters['search'] ?? null;

        if (! $search) {
            return $rows;
        }

        return $rows->filter(fn ($row) => str_contains(strtolower($row->sku), strtolower($search))
            || str_contains(strtolower($row->product_name), strtolower($search)));
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function paginate(Collection $rows, int $page, int $perPage): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : 20;
        $page = $page > 0 ? $page : 1;

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}
