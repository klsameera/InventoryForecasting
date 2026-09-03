<?php

declare(strict_types=1);

namespace Domain\Services\InventoryAnalyticsService;

use App\Enums\AbcClass;
use App\Enums\MovementSpeed;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — deterministic inventory analytics (app_plan.md §79). This module
 * deliberately has no migration or model: it computes derived metrics
 * on-the-fly from tables Phases 1–2 already own (`inventories`,
 * `stock_movements`, `inventory_batches`, `sales_orders`/`sales_order_items`,
 * `supplier_skus`), and never writes anything. There is nothing to persist,
 * so this Service's constructor takes no model — the one deviation from
 * every other Service in the codebase, and the reason there's no
 * corresponding Resource class either: a `JsonResource` wraps an Eloquent
 * model, and there isn't one here, just a plain computed array shape.
 *
 * No ML, no forecasting — every number here is a straight aggregation over
 * actual historical movements. app_plan.md §79 is explicit that this phase
 * exists to "calculate deterministic metrics" *before* ML.
 */
final class InventoryAnalyticsService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function report(array $filters = []): LengthAwarePaginator
    {
        $lookbackDays = max(1, (int) ($filters['lookback_days'] ?? 30));
        $safetyDays = max(0, (int) ($filters['safety_days'] ?? 7));
        $periodStart = now()->subDays($lookbackDays);

        $rows = $this->baseRows($filters);

        if ($rows->isEmpty()) {
            return $this->paginate(collect(), (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
        }

        $pairs = $rows->map(fn ($row) => ['warehouse_id' => $row->warehouse_id, 'sku_id' => $row->sku_id]);

        $netUnitsSold = $this->netUnitsSoldByPair($pairs, $periodStart);
        $revenue = $this->revenueByPair($pairs, $periodStart);
        $weightedAge = $this->weightedAgeByPair($pairs);
        $leadTimeBySku = $this->leadTimeBySku($pairs->pluck('sku_id')->unique());

        $computed = $rows->map(function ($row) use ($lookbackDays, $safetyDays, $netUnitsSold, $revenue, $weightedAge, $leadTimeBySku) {
            $key = "{$row->warehouse_id}:{$row->sku_id}";
            $unitsSold = (int) ($netUnitsSold[$key] ?? 0);
            $dailyVelocity = round($unitsSold / $lookbackDays, 4);
            $leadTimeDays = $leadTimeBySku[$row->sku_id] ?? null;

            $reorderPoint = $leadTimeDays !== null
                ? (int) ceil($dailyVelocity * ($leadTimeDays + $safetyDays))
                : null;

            return (object) [
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $row->warehouse_name,
                'sku_id' => (int) $row->sku_id,
                'sku' => $row->sku,
                'product_name' => $row->product_name,
                'category_name' => $row->category_name,
                'on_hand_qty' => (int) $row->on_hand_qty,
                'available_qty' => (int) $row->available_qty,
                'units_sold_period' => $unitsSold,
                'daily_velocity' => $dailyVelocity,
                'days_of_stock' => $dailyVelocity > 0 ? round($row->available_qty / $dailyVelocity, 1) : null,
                'turnover_ratio' => $row->on_hand_qty > 0 ? round($unitsSold / $row->on_hand_qty, 2) : null,
                'weighted_age_days' => $weightedAge[$key] ?? null,
                'revenue_period' => round($revenue[$key] ?? 0.0, 2),
                'lead_time_days' => $leadTimeDays,
                'reorder_point' => $reorderPoint,
                'needs_reorder' => $reorderPoint !== null && $row->available_qty <= $reorderPoint,
            ];
        });

        $classified = $this->classifyAbc($computed);
        $classified = $this->classifyMovementSpeed($classified);

        $filtered = $this->applySearchAndSort($classified, $filters);

        return $this->paginate($filtered, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function baseRows(array $filters): Collection
    {
        return DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('skus', 'skus.id', '=', 'inventories.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('inventories.warehouse_id', $warehouseId))
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query
                ->where('products.category_id', $categoryId))
            ->select([
                'inventories.warehouse_id',
                'inventories.sku_id',
                'inventories.on_hand_qty',
                'inventories.available_qty',
                'warehouses.name as warehouse_name',
                'skus.sku',
                'products.name as product_name',
                'categories.name as category_name',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, array{warehouse_id: int, sku_id: int}>  $pairs
     * @return array<string, int>
     */
    private function netUnitsSoldByPair(Collection $pairs, CarbonInterface $periodStart): array
    {
        $rows = DB::table('stock_movements')
            ->whereIn('warehouse_id', $pairs->pluck('warehouse_id')->unique())
            ->whereIn('sku_id', $pairs->pluck('sku_id')->unique())
            ->whereIn('movement_type', ['SALE', 'SALE_RETURN'])
            ->where('occurred_at', '>=', $periodStart)
            ->selectRaw('warehouse_id, sku_id, movement_type, SUM(quantity) as total')
            ->groupBy('warehouse_id', 'sku_id', 'movement_type')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $key = "{$row->warehouse_id}:{$row->sku_id}";
            $totals[$key] ??= 0;
            $totals[$key] += $row->movement_type === 'SALE' ? (int) $row->total : -(int) $row->total;
        }

        return array_map(fn ($total) => max(0, $total), $totals);
    }

    /**
     * @param  Collection<int, array{warehouse_id: int, sku_id: int}>  $pairs
     * @return array<string, float>
     */
    private function revenueByPair(Collection $pairs, CarbonInterface $periodStart): array
    {
        $rows = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereIn('sales_orders.warehouse_id', $pairs->pluck('warehouse_id')->unique())
            ->whereIn('sales_order_items.sku_id', $pairs->pluck('sku_id')->unique())
            ->where('sales_orders.status', 'confirmed')
            ->where('sales_orders.order_date', '>=', $periodStart)
            ->selectRaw('sales_orders.warehouse_id, sales_order_items.sku_id, SUM(sales_order_items.net_amount) as total')
            ->groupBy('sales_orders.warehouse_id', 'sales_order_items.sku_id')
            ->get();

        return $rows->mapWithKeys(fn ($row) => ["{$row->warehouse_id}:{$row->sku_id}" => (float) $row->total])->all();
    }

    /**
     * @param  Collection<int, array{warehouse_id: int, sku_id: int}>  $pairs
     * @return array<string, float>
     */
    private function weightedAgeByPair(Collection $pairs): array
    {
        $batches = DB::table('inventory_batches')
            ->whereIn('warehouse_id', $pairs->pluck('warehouse_id')->unique())
            ->whereIn('sku_id', $pairs->pluck('sku_id')->unique())
            ->where('remaining_qty', '>', 0)
            ->select(['warehouse_id', 'sku_id', 'remaining_qty', 'received_date'])
            ->get()
            ->groupBy(fn ($row) => "{$row->warehouse_id}:{$row->sku_id}");

        return $batches->map(function (Collection $group) {
            $totalQty = $group->sum('remaining_qty');

            if ($totalQty <= 0) {
                return null;
            }

            $weightedDays = $group->sum(fn ($row) => $row->remaining_qty * abs(now()->diffInDays($row->received_date)));

            return round($weightedDays / $totalQty, 1);
        })->filter(fn ($age) => $age !== null)->all();
    }

    /**
     * @param  Collection<int, int>  $skuIds
     * @return array<int, int>
     */
    private function leadTimeBySku(Collection $skuIds): array
    {
        $rows = DB::table('supplier_skus')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_skus.supplier_id')
            ->whereIn('supplier_skus.sku_id', $skuIds)
            ->where('supplier_skus.is_primary', true)
            ->select([
                'supplier_skus.sku_id',
                DB::raw('COALESCE(supplier_skus.expected_lead_time_days, suppliers.default_lead_time_days) as lead_time_days'),
            ])
            ->get();

        return $rows->mapWithKeys(fn ($row) => [(int) $row->sku_id => (int) $row->lead_time_days])->all();
    }

    /**
     * Ranks by trailing revenue and buckets into A (top ~80% of cumulative
     * revenue), B (next ~15%), C (the remainder) — app_plan.md §46's example
     * thresholds. Items with no revenue in the period are Unclassified
     * rather than forced into C.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function classifyAbc(Collection $rows): Collection
    {
        $totalRevenue = $rows->sum('revenue_period');

        if ($totalRevenue <= 0) {
            return $rows->map(function ($row) {
                $row->abc_class = AbcClass::Unclassified->value;
                $row->abc_class_label = AbcClass::Unclassified->label();

                return $row;
            });
        }

        $sorted = $rows->sortByDesc('revenue_period')->values();
        $cumulative = 0.0;

        $classified = $sorted->map(function ($row) use (&$cumulative, $totalRevenue) {
            if ($row->revenue_period <= 0) {
                $row->abc_class = AbcClass::Unclassified->value;
                $row->abc_class_label = AbcClass::Unclassified->label();

                return $row;
            }

            $cumulative += $row->revenue_period;
            $cumulativePercent = $cumulative / $totalRevenue;

            $class = match (true) {
                $cumulativePercent <= 0.80 => AbcClass::A,
                $cumulativePercent <= 0.95 => AbcClass::B,
                default => AbcClass::C,
            };

            $row->abc_class = $class->value;
            $row->abc_class_label = $class->label();

            return $row;
        });

        return $classified->values();
    }

    /**
     * Fast = top tertile of daily velocity among items that sold at all;
     * Dead = on hand but zero net sales in the period; Slow = everything
     * else with some velocity; No stock = neither on hand nor sold.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function classifyMovementSpeed(Collection $rows): Collection
    {
        $velocities = $rows->pluck('daily_velocity')->filter(fn ($v) => $v > 0)->sort()->values();
        $fastThreshold = $velocities->isEmpty()
            ? null
            : $velocities->get((int) floor($velocities->count() * 0.66));

        return $rows->map(function ($row) use ($fastThreshold) {
            $speed = match (true) {
                $row->daily_velocity <= 0 && $row->on_hand_qty > 0 => MovementSpeed::Dead,
                $row->daily_velocity <= 0 => MovementSpeed::NotApplicable,
                $fastThreshold !== null && $row->daily_velocity >= $fastThreshold => MovementSpeed::Fast,
                default => MovementSpeed::Slow,
            };

            $row->movement_speed = $speed->value;
            $row->movement_speed_label = $speed->label();

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function applySearchAndSort(Collection $rows, array $filters): Collection
    {
        $search = $filters['search'] ?? null;

        if ($search) {
            $rows = $rows->filter(fn ($row) => str_contains(strtolower($row->sku), strtolower($search))
                || str_contains(strtolower($row->product_name), strtolower($search)));
        }

        if (($filters['movement_speed'] ?? null)) {
            $rows = $rows->filter(fn ($row) => $row->movement_speed === $filters['movement_speed']);
        }

        if (($filters['abc_class'] ?? null)) {
            $rows = $rows->filter(fn ($row) => $row->abc_class === $filters['abc_class']);
        }

        if (! empty($filters['needs_reorder'])) {
            $rows = $rows->filter(fn ($row) => $row->needs_reorder);
        }

        $sort = $filters['sort'] ?? null;
        $sortable = ['sku', 'daily_velocity', 'days_of_stock', 'turnover_ratio', 'weighted_age_days', 'revenue_period', 'available_qty'];

        if (in_array($sort, $sortable, true)) {
            $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $rows = $direction === 'desc' ? $rows->sortByDesc($sort) : $rows->sortBy($sort);
        } else {
            $rows = $rows->sortByDesc('revenue_period');
        }

        return $rows->values();
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
