<?php

declare(strict_types=1);

namespace Domain\Services\ProductRelationshipService;

use App\Enums\ForecastMaturity;
use Domain\Facades\ForecastMaturityFacade\ForecastMaturityFacade;
use Domain\Services\DemandProfileService\DemandProfileService;
use Domain\Services\ForecastMaturityService\ForecastMaturityService;
use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 (app_plan.md §86) — three SKU-to-SKU relationship signals,
 * grouped in one Service because all three answer variations of "how does
 * this SKU relate to others," not because they share a formula. No
 * migration, no model, nothing persisted — computed on request, the same
 * shape as {@see InventoryAnalyticsService}.
 */
final class ProductRelationshipService
{
    /** Window both correlation-based signals below compute over. */
    private const LOOKBACK_DAYS = 90;

    /**
     * Pearson correlation at or below this (moderate-to-strong negative)
     * flags a pair as cannibalization candidates. A round number, not
     * calibrated against any real "did one actually steal the other's
     * sales" outcome data — there isn't any yet.
     */
    private const CANNIBALIZATION_CORRELATION_THRESHOLD = -0.5;

    /** Fewer overlapping days than this isn't enough to trust a correlation. */
    private const MIN_OVERLAPPING_DAYS = 14;

    /**
     * app_plan.md §86's "automatic product similarity" — surfaces the same
     * category+size → brand+category → category peer-tier walk
     * {@see DemandProfileService}
     * already uses internally for cold-start forecasting (app_architecture.md
     * §1j), but returns the peer SKUs themselves rather than an aggregated
     * demand series. Recomputed here rather than shared — that method
     * returns a demand number, this one returns identities; the tier-walk
     * shape is duplicated, not the query results, the same trade-off §1k/§1l
     * already made for small per-pair lookups.
     *
     * @return array{tier: string|null, skus: list<object>}
     */
    public function similarSkus(int $skuId, int $limit = 10): array
    {
        $sku = DB::table('skus')->where('id', $skuId)->first(['product_id', 'product_variant_id']);

        if ($sku === null) {
            return ['tier' => null, 'skus' => []];
        }

        $product = DB::table('products')->where('id', $sku->product_id)->first(['category_id', 'brand_id']);

        if ($product === null) {
            return ['tier' => null, 'skus' => []];
        }

        $sizeAttributeValueId = $sku->product_variant_id !== null
            ? DB::table('variant_attribute_values')
                ->join('attributes', 'attributes.id', '=', 'variant_attribute_values.attribute_id')
                ->where('variant_attribute_values.product_variant_id', $sku->product_variant_id)
                ->where('attributes.forecast_relevant', true)
                ->value('variant_attribute_values.attribute_value_id')
            : null;

        if ($sizeAttributeValueId !== null) {
            $peers = $this->peerSkus($skuId, (int) $product->category_id, $limit, sizeAttributeValueId: (int) $sizeAttributeValueId);

            if ($peers->isNotEmpty()) {
                return ['tier' => 'category_size', 'skus' => $peers->all()];
            }
        }

        if ($product->brand_id !== null) {
            $peers = $this->peerSkus($skuId, (int) $product->category_id, $limit, brandId: (int) $product->brand_id);

            if ($peers->isNotEmpty()) {
                return ['tier' => 'brand_category', 'skus' => $peers->all()];
            }
        }

        $peers = $this->peerSkus($skuId, (int) $product->category_id, $limit);

        if ($peers->isNotEmpty()) {
            return ['tier' => 'category', 'skus' => $peers->all()];
        }

        return ['tier' => null, 'skus' => []];
    }

    /**
     * @return Collection<int, object>
     */
    private function peerSkus(int $excludeSkuId, int $categoryId, int $limit, ?int $brandId = null, ?int $sizeAttributeValueId = null): Collection
    {
        $query = DB::table('skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('products.category_id', $categoryId)
            ->where('skus.id', '!=', $excludeSkuId);

        if ($brandId !== null) {
            $query->where('products.brand_id', $brandId);
        }

        if ($sizeAttributeValueId !== null) {
            $query->join('variant_attribute_values', 'variant_attribute_values.product_variant_id', '=', 'skus.product_variant_id')
                ->where('variant_attribute_values.attribute_value_id', $sizeAttributeValueId);
        }

        return $query->select(['skus.id', 'skus.sku', 'products.name as product_name'])
            ->distinct()
            ->limit($limit)
            ->get();
    }

    /**
     * app_plan.md §86's "product cannibalization" — pairs of SKUs **in the
     * same category** whose daily `sold_qty` (Phase 4's
     * `inventory_daily_snapshots`) correlates negatively enough over the
     * window to suggest one selling more coincides with the other selling
     * less. Scoped to same-category pairs, not the whole catalog — both a
     * reasonable business assumption (substitutes are usually in the same
     * category) and what keeps the pairwise comparison bounded; this is
     * still an **O(n²) comparison within each category**, a real scaling
     * limitation for a very large catalog, not hidden from the docs.
     *
     * **Correlation is not causation** — a strong negative correlation is a
     * candidate worth a human look, not a proven cannibalization effect.
     *
     * @param  array<string, mixed>  $filters
     */
    public function cannibalizationCandidates(array $filters = []): LengthAwarePaginator
    {
        $lookbackDays = max(1, (int) ($filters['lookback_days'] ?? self::LOOKBACK_DAYS));
        $since = now()->subDays($lookbackDays)->toDateString();

        $rows = DB::table('inventory_daily_snapshots')
            ->join('skus', 'skus.id', '=', 'inventory_daily_snapshots.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereDate('inventory_daily_snapshots.snapshot_date', '>=', $since)
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query
                ->where('products.category_id', $categoryId))
            ->select([
                'products.category_id',
                'inventory_daily_snapshots.sku_id',
                DB::raw('DATE(inventory_daily_snapshots.snapshot_date) as bucket_date'),
                'inventory_daily_snapshots.sold_qty',
                'skus.sku',
                'products.name as product_name',
            ])
            ->get()
            ->groupBy('category_id');

        $candidates = collect();

        foreach ($rows as $categoryRows) {
            $bySku = $categoryRows->groupBy('sku_id');
            $skuIds = $bySku->keys()->values();

            for ($i = 0; $i < $skuIds->count(); $i++) {
                for ($j = $i + 1; $j < $skuIds->count(); $j++) {
                    $skuA = $bySku[$skuIds[$i]];
                    $skuB = $bySku[$skuIds[$j]];

                    $correlation = $this->correlate(
                        $skuA->pluck('sold_qty', 'bucket_date'),
                        $skuB->pluck('sold_qty', 'bucket_date'),
                    );

                    if ($correlation === null || $correlation > self::CANNIBALIZATION_CORRELATION_THRESHOLD) {
                        continue;
                    }

                    $candidates->push((object) [
                        'sku_a_id' => $skuIds[$i],
                        'sku_a' => $skuA->first()->sku,
                        'product_a_name' => $skuA->first()->product_name,
                        'sku_b_id' => $skuIds[$j],
                        'sku_b' => $skuB->first()->sku,
                        'product_b_name' => $skuB->first()->product_name,
                        'correlation' => round($correlation, 2),
                    ]);
                }
            }
        }

        $filtered = $this->applyPairSearch($candidates, $filters, ['sku_a', 'product_a_name'], ['sku_b', 'product_b_name'])
            ->sortBy('correlation')
            ->values();

        return $this->paginate($filtered, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * Pearson correlation coefficient over each series' overlapping dates
     * only. Returns null when there isn't enough overlap, or either series
     * has zero variance — an undefined correlation, never a fabricated one.
     *
     * @param  Collection<string, int>  $seriesA
     * @param  Collection<string, int>  $seriesB
     */
    private function correlate(Collection $seriesA, Collection $seriesB): ?float
    {
        $dates = $seriesA->keys()->intersect($seriesB->keys())->values();

        if ($dates->count() < self::MIN_OVERLAPPING_DAYS) {
            return null;
        }

        $x = $dates->map(fn ($date) => (float) $seriesA[$date]);
        $y = $dates->map(fn ($date) => (float) $seriesB[$date]);

        $meanX = $x->avg();
        $meanY = $y->avg();

        $numerator = $dates->sum(fn ($date, $index) => ($x[$index] - $meanX) * ($y[$index] - $meanY));
        $denomX = sqrt($x->sum(fn ($value) => ($value - $meanX) ** 2));
        $denomY = sqrt($y->sum(fn ($value) => ($value - $meanY) ** 2));

        if ($denomX <= 0.0 || $denomY <= 0.0) {
            return null;
        }

        return $numerator / ($denomX * $denomY);
    }

    /**
     * app_plan.md §86's "successor detection" — pairs a SKU whose
     * {@see ForecastMaturityService}
     * classification is Declining/EndOfLife with a Cold-start/Early SKU in
     * the **same category and brand** — a real, if heuristic, "this old
     * line might be getting replaced by that new one" signal, built
     * entirely from real maturity classifications rather than an explicit
     * "successor of" link this schema has no concept of.
     *
     * **Classifies every SKU's maturity** (one call each), a real N+1-style
     * cost for a large catalog — filter by `category_id` to keep this
     * bounded, the same caveat {@see cannibalizationCandidates()} has for
     * its own O(n²) pairing.
     *
     * @param  array<string, mixed>  $filters
     */
    public function successorCandidates(array $filters = []): LengthAwarePaginator
    {
        $skus = DB::table('skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query
                ->where('products.category_id', $categoryId))
            ->select(['skus.id', 'skus.sku', 'products.name as product_name', 'products.category_id', 'products.brand_id'])
            ->get();

        $classified = $skus->map(fn ($sku) => (object) [
            'id' => $sku->id,
            'sku' => $sku->sku,
            'product_name' => $sku->product_name,
            'category_id' => $sku->category_id,
            'brand_id' => $sku->brand_id,
            'maturity' => ForecastMaturityFacade::classify($sku->id)['maturity'],
        ]);

        $declining = $classified->whereIn('maturity', [ForecastMaturity::Declining, ForecastMaturity::EndOfLife]);
        $new = $classified->whereIn('maturity', [ForecastMaturity::ColdStart, ForecastMaturity::Early]);

        $candidates = collect();

        foreach ($declining as $decliningSku) {
            if ($decliningSku->brand_id === null) {
                continue;
            }

            $matches = $new
                ->where('category_id', $decliningSku->category_id)
                ->where('brand_id', $decliningSku->brand_id)
                ->where('id', '!=', $decliningSku->id);

            foreach ($matches as $newSku) {
                $candidates->push((object) [
                    'declining_sku_id' => $decliningSku->id,
                    'declining_sku' => $decliningSku->sku,
                    'declining_product_name' => $decliningSku->product_name,
                    'declining_maturity' => $decliningSku->maturity->value,
                    'declining_maturity_label' => $decliningSku->maturity->label(),
                    'successor_sku_id' => $newSku->id,
                    'successor_sku' => $newSku->sku,
                    'successor_product_name' => $newSku->product_name,
                    'successor_maturity' => $newSku->maturity->value,
                    'successor_maturity_label' => $newSku->maturity->label(),
                ]);
            }
        }

        $filtered = $this->applyPairSearch($candidates, $filters, ['declining_sku', 'declining_product_name'], ['successor_sku', 'successor_product_name'])
            ->values();

        return $this->paginate($filtered, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: string}  $sideAFields
     * @param  array{0: string, 1: string}  $sideBFields
     * @return Collection<int, object>
     */
    private function applyPairSearch(Collection $rows, array $filters, array $sideAFields, array $sideBFields): Collection
    {
        $search = $filters['search'] ?? null;

        if (! $search) {
            return $rows;
        }

        $needle = strtolower($search);

        return $rows->filter(function ($row) use ($needle, $sideAFields, $sideBFields) {
            foreach ([...$sideAFields, ...$sideBFields] as $field) {
                if (str_contains(strtolower((string) $row->{$field}), $needle)) {
                    return true;
                }
            }

            return false;
        });
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
