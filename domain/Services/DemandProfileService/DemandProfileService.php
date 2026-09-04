<?php

declare(strict_types=1);

namespace Domain\Services\DemandProfileService;

use App\Enums\ForecastSource;
use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Domain\Services\MlServiceClient\MlServiceClient;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
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

        // Peer demand has to come from the same source the forecast is served
        // from. Reading the ledger while serving synced demand would build the
        // fallback series out of a different dataset than the one the cold-start
        // SKU is being compared against.
        $buyabans = MlTrainingDataService::demandSource() === MlTrainingDataService::SOURCE_BUYABANS;
        $table = $buyabans ? 'buyabans_daily_demands' : 'inventory_daily_snapshots';
        $dateColumn = $buyabans ? 'demand_date' : 'snapshot_date';

        $query = DB::table($table)
            ->join('skus', 'skus.id', '=', $table.'.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where($table.'.warehouse_id', $warehouseId)
            ->where('products.category_id', $categoryId)
            ->where($table.'.sku_id', '!=', $excludeSkuId)
            ->when($buyabans, fn ($builder) => $builder->where($table.'.grain', config('services.buyabans.grain', 'warehouse')))
            ->whereDate($table.'.'.$dateColumn, '>=', $since);

        if ($brandId !== null) {
            $query->where('products.brand_id', $brandId);
        }

        if ($sizeAttributeValueId !== null) {
            $query->join('variant_attribute_values', 'variant_attribute_values.product_variant_id', '=', 'skus.product_variant_id')
                ->where('variant_attribute_values.attribute_value_id', $sizeAttributeValueId);
        }

        $peerCount = (int) (clone $query)->distinct()->count($table.'.sku_id');

        if ($peerCount === 0) {
            return null;
        }

        $totalsByDate = $query
            ->selectRaw("DATE({$table}.{$dateColumn}) as bucket_date, SUM({$table}.sold_qty) as total_qty")
            ->groupBy(DB::raw("DATE({$table}.{$dateColumn})"))
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
