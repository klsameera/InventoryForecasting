<?php

declare(strict_types=1);

namespace Domain\Services\DemandProfileService;

use App\Enums\ForecastSource;
use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Domain\Services\MlServiceClient\MlServiceClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a fallback demand series for a SKU with no (or too little) sales
 * history of its own, by aggregating peer SKUs — app_plan.md §26/§27's
 * "category-size" and "brand-category" demand profiles, and the fallback
 * hierarchy that walks from the most specific peer group to the broadest.
 *
 * No migration, no model, nothing persisted — every call recomputes from
 * whatever `inventory_daily_snapshots` rows currently exist, the same
 * "computed, no model" shape as {@see InventoryAnalyticsService}
 * and {@see MlServiceClient}.
 *
 * **Deliberately simple, matching app_plan.md §29's "you shouldn't
 * necessarily hard-code these percentages; models can learn them" —**
 * this returns the *average daily sold quantity per peer SKU*, not a
 * demand-weighted size-curve (§30's more elaborate two-forecast approach).
 * A real model would learn a proper size/category curve from more signals
 * than this scaffold has; this is the honest, basic starting point.
 */
final class DemandProfileService
{
    /**
     * @return array{source: ForecastSource, daily_sold_qty: list<float>}
     */
    public function fallbackSeries(int $warehouseId, int $skuId, int $days): array
    {
        $sku = DB::table('skus')->where('id', $skuId)->first(['product_id', 'product_variant_id']);

        if ($sku === null) {
            return ['source' => ForecastSource::ColdStart, 'daily_sold_qty' => array_fill(0, $days, 0.0)];
        }

        $product = DB::table('products')->where('id', $sku->product_id)->first(['category_id', 'brand_id']);

        if ($product === null) {
            return ['source' => ForecastSource::ColdStart, 'daily_sold_qty' => array_fill(0, $days, 0.0)];
        }

        $sizeAttributeValueId = $sku->product_variant_id !== null
            ? DB::table('variant_attribute_values')
                ->join('attributes', 'attributes.id', '=', 'variant_attribute_values.attribute_id')
                ->where('variant_attribute_values.product_variant_id', $sku->product_variant_id)
                ->where('attributes.forecast_relevant', true)
                ->value('variant_attribute_values.attribute_value_id')
            : null;

        if ($sizeAttributeValueId !== null) {
            $series = $this->peerSeries($warehouseId, $days, $skuId, (int) $product->category_id, sizeAttributeValueId: (int) $sizeAttributeValueId);

            if ($series !== null) {
                return ['source' => ForecastSource::CategorySize, 'daily_sold_qty' => $series];
            }
        }

        if ($product->brand_id !== null) {
            $series = $this->peerSeries($warehouseId, $days, $skuId, (int) $product->category_id, brandId: (int) $product->brand_id);

            if ($series !== null) {
                return ['source' => ForecastSource::BrandCategory, 'daily_sold_qty' => $series];
            }
        }

        $series = $this->peerSeries($warehouseId, $days, $skuId, (int) $product->category_id);

        if ($series !== null) {
            return ['source' => ForecastSource::Category, 'daily_sold_qty' => $series];
        }

        return ['source' => ForecastSource::ColdStart, 'daily_sold_qty' => array_fill(0, $days, 0.0)];
    }

    /**
     * @return list<float>|null null when no peer SKU has any history at all
     *                          — an honest "nothing to base this on" rather
     *                          than a fabricated series.
     */
    private function peerSeries(
        int $warehouseId,
        int $days,
        int $excludeSkuId,
        int $categoryId,
        ?int $brandId = null,
        ?int $sizeAttributeValueId = null,
    ): ?array {
        $since = now()->subDays($days)->toDateString();

        $query = DB::table('inventory_daily_snapshots')
            ->join('skus', 'skus.id', '=', 'inventory_daily_snapshots.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('inventory_daily_snapshots.warehouse_id', $warehouseId)
            ->where('products.category_id', $categoryId)
            ->where('inventory_daily_snapshots.sku_id', '!=', $excludeSkuId)
            ->whereDate('inventory_daily_snapshots.snapshot_date', '>=', $since);

        if ($brandId !== null) {
            $query->where('products.brand_id', $brandId);
        }

        if ($sizeAttributeValueId !== null) {
            $query->join('variant_attribute_values', 'variant_attribute_values.product_variant_id', '=', 'skus.product_variant_id')
                ->where('variant_attribute_values.attribute_value_id', $sizeAttributeValueId);
        }

        $peerCount = (int) (clone $query)->distinct()->count('inventory_daily_snapshots.sku_id');

        if ($peerCount === 0) {
            return null;
        }

        $totalsByDate = $query
            ->selectRaw('DATE(inventory_daily_snapshots.snapshot_date) as bucket_date, SUM(inventory_daily_snapshots.sold_qty) as total_qty')
            ->groupBy(DB::raw('DATE(inventory_daily_snapshots.snapshot_date)'))
            ->pluck('total_qty', 'bucket_date');

        $series = [];
        $cursor = Carbon::parse($since);

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->copy()->addDays($i)->toDateString();
            $total = (float) ($totalsByDate[$date] ?? 0);
            $series[] = round($total / $peerCount, 2);
        }

        return $series;
    }
}
