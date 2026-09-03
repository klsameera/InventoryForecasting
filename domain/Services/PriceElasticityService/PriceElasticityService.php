<?php

declare(strict_types=1);

namespace Domain\Services\PriceElasticityService;

use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 (app_plan.md §86) — "price elasticity," estimated from real
 * historical selling-price variation already sitting in
 * `sales_order_items.unit_price` (Phase 2) — no price-history table exists
 * or is needed; a SKU's own confirmed sales orders already record what it
 * actually sold for, order by order. No migration, no model, nothing
 * persisted — computed on request, the same shape as
 * {@see InventoryAnalyticsService}.
 *
 * A SKU that has only ever sold at one price has no real price variation to
 * learn an elasticity from and is skipped — an honest "not enough data,"
 * not a fabricated coefficient.
 */
final class PriceElasticityService
{
    /** Fewer distinct historical prices than this can't fit a meaningful slope. */
    private const MIN_DISTINCT_PRICE_POINTS = 2;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function report(array $filters = []): LengthAwarePaginator
    {
        $lookbackDays = max(1, (int) ($filters['lookback_days'] ?? 365));
        $since = now()->subDays($lookbackDays);

        $rows = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('skus', 'skus.id', '=', 'sales_order_items.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('sales_orders.status', 'confirmed')
            ->where('sales_orders.order_date', '>=', $since)
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sales_order_items.sku_id', $skuId))
            ->select([
                'sales_order_items.sku_id',
                'sales_order_items.unit_price',
                'sales_order_items.quantity',
                'skus.sku',
                'products.name as product_name',
            ])
            ->get()
            ->groupBy('sku_id');

        $computed = $rows->map(function (Collection $group) {
            $byPrice = $group->groupBy(fn ($row) => (float) $row->unit_price)
                ->map(fn (Collection $atPrice, $price) => (object) [
                    'price' => (float) $price,
                    'quantity' => $atPrice->sum('quantity'),
                ]);

            if ($byPrice->count() < self::MIN_DISTINCT_PRICE_POINTS) {
                return null;
            }

            $elasticity = $this->logLogSlope($byPrice->values());

            if ($elasticity === null) {
                return null;
            }

            $first = $group->first();

            return (object) [
                'sku_id' => (int) $first->sku_id,
                'sku' => $first->sku,
                'product_name' => $first->product_name,
                'distinct_price_points' => $byPrice->count(),
                'elasticity' => round($elasticity, 2),
                'interpretation' => $this->interpret($elasticity),
            ];
        })->filter()->values();

        $filtered = $this->applySearch($computed, $filters)->sortBy('elasticity')->values();

        return $this->paginate($filtered, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * Ordinary-least-squares slope of `ln(quantity)` on `ln(price)` across
     * a SKU's own distinct historical price points — the standard way to
     * read a demand curve's percentage-change-per-percentage-change as a
     * single coefficient. A price point that sold zero units has no
     * `ln(quantity)` and is dropped rather than treated as a fabricated
     * near-zero demand.
     *
     * @param  Collection<int, object{price: float, quantity: int}>  $pricePoints
     */
    private function logLogSlope(Collection $pricePoints): ?float
    {
        $points = $pricePoints
            ->filter(fn ($point) => $point->price > 0 && $point->quantity > 0)
            ->map(fn ($point) => ['x' => log($point->price), 'y' => log($point->quantity)])
            ->values();

        if ($points->count() < self::MIN_DISTINCT_PRICE_POINTS) {
            return null;
        }

        $meanX = $points->avg('x');
        $meanY = $points->avg('y');

        $numerator = $points->sum(fn ($point) => ($point['x'] - $meanX) * ($point['y'] - $meanY));
        $denominator = $points->sum(fn ($point) => ($point['x'] - $meanX) ** 2);

        if ($denominator <= 0.0) {
            return null;
        }

        return $numerator / $denominator;
    }

    private function interpret(float $elasticity): string
    {
        return match (true) {
            $elasticity > 0 => 'Unusual (demand rose with price)',
            $elasticity >= -1 => 'Inelastic (demand changes less than price)',
            default => 'Elastic (demand changes more than price)',
        };
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

        $needle = strtolower($search);

        return $rows->filter(fn ($row) => str_contains(strtolower($row->sku), $needle)
            || str_contains(strtolower($row->product_name), $needle));
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
