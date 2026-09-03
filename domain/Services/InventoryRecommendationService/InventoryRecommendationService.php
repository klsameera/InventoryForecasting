<?php

declare(strict_types=1);

namespace Domain\Services\InventoryRecommendationService;

use App\Enums\OverstockRisk;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Enums\StockoutRisk;
use App\Models\Forecast;
use App\Models\InventoryRecommendation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * app_plan.md §40's inventory decision engine: Forecast + current/incoming
 * stock + supplier lead time + dynamic safety stock + MOQ/order multiple →
 * a recommendation. Phase 7 (app_plan.md §83) produced only
 * {@see RecommendationType::Purchase} rows; Phase 8 (app_plan.md §84, "ageing
 * prevention") adds {@see RecommendationType::ReducePurchase},
 * {@see RecommendationType::DoNotReorder} and
 * {@see RecommendationType::Clearance} — see app_architecture.md §1l. Phase 9
 * (app_plan.md §85, "multi-location optimization") adds
 * {@see RecommendationType::TransferStock}, matching a shortage in one
 * warehouse against real surplus in another for the same SKU instead of
 * always buying — see app_architecture.md §1m — plus {@see centralAllocation()},
 * a read-only cross-warehouse rollup of open purchase-type recommendations.
 *
 * {@see generate()} requires a completed {@see Forecast} to
 * already exist for a pair — if nobody has ever run a forecast for a
 * warehouse/SKU, there is nothing to base a recommendation on, so that pair
 * is silently skipped rather than falling back to a fabricated number. This
 * is why Phase 7 could not have come before Phase 5/6.
 */
final class InventoryRecommendationService
{
    public function __construct(private InventoryRecommendation $model) {}

    /**
     * Order-up-to buffer added on top of the reorder point when sizing a
     * recommended quantity, so purchasing isn't ordering the bare minimum
     * every single time — a simple (s, S) policy, not §44's full dynamic
     * model.
     */
    private const REVIEW_PERIOD_DAYS = 14;

    /** Window used to compute demand variability for the safety-stock formula. */
    private const DEMAND_STD_DEV_LOOKBACK_DAYS = 90;

    /**
     * Used only when fewer than two days of demand history exist to compute
     * a standard deviation from — the same "basic version" fallback Phase 3
     * used before this phase existed.
     */
    private const FALLBACK_SAFETY_DAYS = 7;

    /**
     * A single fixed ~95% service level (z ≈ 1.65) for every SKU. app_plan.md
     * §45's per-ABC-class service levels (A 98% / B 95% / C 90%) are a
     * natural next refinement once this engine also classifies ABC — today
     * only `InventoryAnalyticsService` does that, computed per-request
     * inside a paginated report rather than as a reusable per-SKU lookup.
     * Documented simplification, same category as this engine's other
     * "basic version first" choices.
     */
    private const SERVICE_LEVEL_Z = 1.65;

    /**
     * Forward-looking window app_plan.md §48's ageing prediction and §50's
     * sell-through estimate are both measured against — 90 days, matching
     * the plan's own worked examples for both.
     */
    private const AGEING_HORIZON_DAYS = 90;

    /**
     * An {@see ageingRiskScore()} at or above this (0–100) earns
     * `DoNotReorder`; at or above {@see AGEING_RISK_CLEARANCE_THRESHOLD}
     * (checked first, so the more severe label wins) it earns `Clearance`
     * instead. Thresholds are round numbers, not calibrated against real
     * outcomes — there's no historical "did we actually end up with dead
     * stock" data yet to calibrate against, so these are a defensible
     * starting point, not a claim of precision.
     */
    private const AGEING_RISK_DO_NOT_REORDER_THRESHOLD = 50;

    private const AGEING_RISK_CLEARANCE_THRESHOLD = 75;

    /** Below this projected 90-day sell-through percentage, combined with a
     * high ageing risk score, a `Clearance` recommendation is warranted
     * rather than the milder `DoNotReorder` — app_plan.md §50.
     */
    private const LOW_SELL_THROUGH_PERCENT = 30;

    /** `months_of_stock` (app_plan.md §50) at or above these earn Moderate/Critical overstock risk. */
    private const OVERSTOCK_MONTHS_MODERATE = 3.0;

    private const OVERSTOCK_MONTHS_CRITICAL = 6.0;

    public function count(): int
    {
        return $this->model->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): LengthAwarePaginator
    {
        return $this->model
            ->newQuery()
            ->with(['warehouse:id,name', 'sourceWarehouse:id,name', 'sku:id,sku,product_id', 'sku.product:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sku_id', $skuId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query
                ->where('status', $status))
            ->when($filters['recommendation_type'] ?? null, fn ($query, $type) => $query
                ->where('recommendation_type', $type))
            ->when($filters['stockout_risk'] ?? null, fn ($query, $risk) => $query
                ->where('stockout_risk', $risk))
            ->when($filters['overstock_risk'] ?? null, fn ($query, $risk) => $query
                ->where('overstock_risk', $risk))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($skuQuery) => $skuQuery->where('sku', 'like', "%{$search}%")))
            ->orderByRaw("CASE status WHEN 'NEW' THEN 0 WHEN 'REVIEWED' THEN 1 ELSE 2 END")
            ->orderBy('recommended_action_date')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?InventoryRecommendation
    {
        return $this->model->with(['warehouse', 'sourceWarehouse', 'sku.product', 'forecast', 'decidedBy'])->find($id);
    }

    /**
     * Re-runs the recommendation engine over every warehouse/SKU pair in
     * scope (or every pair with an `inventories` row, if `$warehouseIds` is
     * null). Open (`New`/`Reviewed`) recommendations are updated in place;
     * pairs that no longer need one have their open recommendation removed.
     *
     * **A pair that already has a decided row (`Accepted`/`Modified`/
     * `Rejected`/`Completed`) never gets a second one** — app_plan.md §53's
     * human override is treated as final, not just "don't edit it," so a
     * still-needy pair with an existing decision is silently skipped rather
     * than growing a duplicate NEW row alongside it. This is a known Phase 7
     * limitation: nothing in this phase closes the loop (there's no
     * "purchase order fulfilled, this pair can be re-evaluated" signal),
     * so a decided recommendation is effectively terminal until a future
     * phase adds that. See app_architecture.md §1k.
     *
     * **Two passes, not one** (Phase 9, app_plan.md §85): every pair's
     * candidate is built first and held in memory, keyed by SKU, before
     * anything is persisted — {@see matchTransferOpportunities()} needs to
     * see every warehouse's candidate for a SKU at once to find transfer
     * opportunities, which a single-pair-at-a-time loop can't do. Only after
     * that second pass runs does the same persist/update/stale-cleanup logic
     * from Phase 7 execute, unchanged.
     *
     * @param  list<int>|null  $warehouseIds
     * @return array<string, mixed>
     */
    public function generate(?array $warehouseIds = null): array
    {
        DB::beginTransaction();

        try {
            $pairs = $this->pairsInScope($warehouseIds);

            /** @var array<int, array<int, array<string, mixed>>> $candidates sku_id => warehouse_id => data */
            $candidates = [];

            foreach ($pairs as $pair) {
                $data = $this->buildRecommendation($pair['warehouse_id'], $pair['sku_id']);

                if ($data === null) {
                    continue;
                }

                $candidates[$pair['sku_id']][$pair['warehouse_id']] = $data;
            }

            $this->matchTransferOpportunities($candidates);

            $keepKeys = [];
            $created = 0;
            $updated = 0;

            foreach ($candidates as $skuId => $warehouseMap) {
                foreach ($warehouseMap as $warehouseId => $data) {
                    unset($data['_available_qty'], $data['_raw_need_qty']);

                    $keepKeys[] = "{$warehouseId}:{$skuId}";

                    $pairQuery = fn () => $this->model->newQuery()
                        ->where('warehouse_id', $warehouseId)
                        ->where('sku_id', $skuId);

                    $existingOpen = $pairQuery()->whereIn('status', $this->openStatuses())->first();

                    if ($existingOpen !== null) {
                        $existingOpen->update($data);
                        $updated++;

                        continue;
                    }

                    if ($pairQuery()->exists()) {
                        continue;
                    }

                    $this->model->create([...$data, 'warehouse_id' => $warehouseId, 'sku_id' => $skuId]);
                    $created++;
                }
            }

            $staleQuery = $this->model->newQuery()->whereIn('status', $this->openStatuses());

            if ($warehouseIds !== null && $warehouseIds !== []) {
                $staleQuery->whereIn('warehouse_id', $warehouseIds);
            }

            $staleIds = $staleQuery->get()
                ->reject(fn ($row) => in_array("{$row->warehouse_id}:{$row->sku_id}", $keepKeys, true))
                ->pluck('id');

            $this->model->newQuery()->whereIn('id', $staleIds)->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => "Recommendations refreshed: {$created} new, {$updated} updated, {$staleIds->count()} no longer needed.",
                'data' => ['created' => $created, 'updated' => $updated, 'removed' => $staleIds->count()],
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed generating inventory recommendations', ['exception' => $exception->getMessage()]);

            return ['success' => false, 'message' => 'Error generating inventory recommendations'];
        }
    }

    /**
     * app_plan.md §85's transfer recommendation: when a SKU is short in one
     * warehouse and has real, forecast-backed surplus in another, recommend
     * moving stock between them instead of buying more. Mutates
     * `$candidates` in place, turning a `Purchase`/`ReducePurchase`
     * destination into a `TransferStock` row when a match is found; leaves
     * every other candidate (including unmatched destinations and every
     * source) untouched.
     *
     * **Basic version, not a solver** — matches, in order:
     *
     * 1. Only SKUs present in **two or more** warehouses this run are even
     *    considered; a SKU stocked at one location has nothing to transfer
     *    from.
     * 2. A **source** is any `DoNotReorder`/`Clearance` candidate for the
     *    SKU with real surplus — `available_qty` beyond its own 90-day
     *    forecasted demand (the same number `sellThroughPercent()` already
     *    reasons about, just expressed as units instead of a percentage). A
     *    **destination** is any `Purchase`/`ReducePurchase` candidate, with
     *    its need being the raw pre-MOQ/pre-order-multiple shortfall
     *    ({@see buildRecommendation()}'s `_raw_need_qty`) — a transfer isn't
     *    bound by a supplier's pack size.
     * 3. Destinations are matched **largest need first**, each against
     *    whichever remaining source has the **largest surplus that still
     *    covers it in full**. Surplus already promised to an earlier,
     *    bigger destination in this run is not available to a later one.
     * 4. **No partial transfers and no combining multiple sources.** If no
     *    single warehouse's surplus covers a destination's full need, that
     *    destination keeps its original `Purchase`/`ReducePurchase`
     *    recommendation unchanged — it is not topped up by a smaller
     *    transfer plus a reduced purchase. A future phase could relax this;
     *    today it keeps one destination's stock coming from exactly one
     *    place, which is what `StockTransfer` (the execution module this
     *    recommendation feeds) already expects.
     *
     * Nothing here reserves the matched surplus in the database — it only
     * affects which candidates get turned into rows during *this* run. A
     * human still has to act on the `TransferStock` recommendation by
     * creating a real `StockTransfer`, the same "decision recorded,
     * execution is a separate manual step" pattern §1k's `Purchase` rows
     * already have with `PurchaseOrder`.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $candidates  sku_id => warehouse_id => data, mutated in place
     */
    private function matchTransferOpportunities(array &$candidates): void
    {
        $warehouseNames = null;

        foreach ($candidates as $skuId => $warehouseMap) {
            if (count($warehouseMap) < 2) {
                continue;
            }

            $sources = [];
            $destinations = [];

            foreach ($warehouseMap as $warehouseId => $data) {
                if (in_array($data['recommendation_type'], [RecommendationType::DoNotReorder, RecommendationType::Clearance], true)) {
                    $surplus = max(0.0, (float) $data['_available_qty'] - (float) ($data['forecast_90d'] ?? 0.0));

                    if ($surplus > 0.0) {
                        $sources[$warehouseId] = $surplus;
                    }
                } elseif (in_array($data['recommendation_type'], [RecommendationType::Purchase, RecommendationType::ReducePurchase], true)) {
                    $need = (float) ($data['_raw_need_qty'] ?? 0.0);

                    if ($need > 0.0) {
                        $destinations[$warehouseId] = $need;
                    }
                }
            }

            if ($sources === [] || $destinations === []) {
                continue;
            }

            $warehouseNames ??= DB::table('warehouses')->pluck('name', 'id');

            arsort($destinations);

            foreach ($destinations as $destinationWarehouseId => $need) {
                $transferQty = (int) ceil($need);

                arsort($sources);
                $bestSourceId = null;

                foreach ($sources as $sourceWarehouseId => $surplus) {
                    if ($surplus >= $transferQty) {
                        $bestSourceId = $sourceWarehouseId;

                        break;
                    }
                }

                if ($bestSourceId === null) {
                    continue;
                }

                $sources[$bestSourceId] -= $transferQty;

                $candidates[$skuId][$destinationWarehouseId] = [...$candidates[$skuId][$destinationWarehouseId],
                    'recommendation_type' => RecommendationType::TransferStock,
                    'source_warehouse_id' => $bestSourceId,
                    'recommended_qty' => $transferQty,
                    'reason' => $this->buildTransferReason($transferQty, $warehouseNames[$bestSourceId] ?? "warehouse #{$bestSourceId}"),
                ];
            }
        }
    }

    private function buildTransferReason(int $transferQty, string $sourceWarehouseName): string
    {
        return "{$sourceWarehouseName} has surplus stock for this SKU beyond its own 90-day forecasted demand — transfer {$transferQty} unit(s) from there instead of purchasing more.";
    }

    public function review(int $id): array
    {
        return $this->applyDecision($id, function (InventoryRecommendation $recommendation) {
            $recommendation->status = RecommendationStatus::Reviewed;
        }, 'reviewed');
    }

    public function accept(int $id, ?int $userId): array
    {
        return $this->applyDecision($id, function (InventoryRecommendation $recommendation) use ($userId) {
            $recommendation->status = RecommendationStatus::Accepted;
            $recommendation->decided_qty = $recommendation->recommended_qty;
            $recommendation->decided_by = $userId;
            $recommendation->decided_at = now();
        }, 'accepted');
    }

    public function modify(int $id, int $decidedQty, ?string $reason, ?int $userId): array
    {
        return $this->applyDecision($id, function (InventoryRecommendation $recommendation) use ($decidedQty, $reason, $userId) {
            $recommendation->status = RecommendationStatus::Modified;
            $recommendation->decided_qty = $decidedQty;
            $recommendation->decision_reason = $reason;
            $recommendation->decided_by = $userId;
            $recommendation->decided_at = now();
        }, 'modified');
    }

    public function reject(int $id, ?string $reason, ?int $userId): array
    {
        return $this->applyDecision($id, function (InventoryRecommendation $recommendation) use ($reason, $userId) {
            $recommendation->status = RecommendationStatus::Rejected;
            $recommendation->decided_qty = 0;
            $recommendation->decision_reason = $reason;
            $recommendation->decided_by = $userId;
            $recommendation->decided_at = now();
        }, 'rejected');
    }

    /**
     * @return array<string, mixed>
     */
    private function applyDecision(int $id, callable $mutate, string $verb): array
    {
        DB::beginTransaction();

        try {
            $recommendation = $this->model->findOrFail($id);
            $mutate($recommendation);
            $recommendation->save();

            DB::commit();

            return ['success' => true, 'message' => "Recommendation marked as {$verb}", 'data' => $recommendation->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error("Failed marking recommendation as {$verb}", [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating recommendation'];
        }
    }

    /**
     * The full app_plan.md §40 formula for one pair, extended with §84's
     * ageing/overstock evaluation. Returns null when the pair isn't
     * actionable at all — no forecast, no inventory row, or neither a
     * purchase nor an ageing/overstock concern applies this run.
     *
     * @return array<string, mixed>|null
     */
    private function buildRecommendation(int $warehouseId, int $skuId): ?array
    {
        $forecast = DB::table('forecasts')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->orderByDesc('created_at')
            ->first();

        if ($forecast === null || $forecast->horizon_days <= 0) {
            return null;
        }

        $dailyRate = (float) $forecast->predicted_qty / (int) $forecast->horizon_days;

        $inventory = DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->first(['on_hand_qty', 'available_qty', 'reserved_qty', 'incoming_qty']);

        if ($inventory === null) {
            return null;
        }

        $availableQty = (float) $inventory->available_qty;

        $base = [
            'forecast_id' => $forecast->id,
            'source_warehouse_id' => null,
            'current_qty' => (int) $inventory->on_hand_qty,
            'incoming_qty' => (int) $inventory->incoming_qty,
            'forecast_30d' => round($dailyRate * 30, 2),
            'forecast_60d' => round($dailyRate * 60, 2),
            'forecast_90d' => round($dailyRate * self::AGEING_HORIZON_DAYS, 2),
            'confidence_score' => $forecast->confidence_score,
            'ageing_risk' => $this->ageingRiskScore($warehouseId, $skuId, $dailyRate * self::AGEING_HORIZON_DAYS, $availableQty),
            'overstock_risk' => $this->overstockRisk($dailyRate, $availableQty),
            'status' => RecommendationStatus::New,
            // Transient — read by matchTransferOpportunities(), stripped
            // before persisting. Not a DB column.
            '_available_qty' => $availableQty,
        ];

        if ($dailyRate <= 0.0) {
            return $this->ageingOverstockRecommendation($base, $availableQty);
        }

        $supplier = DB::table('supplier_skus')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_skus.supplier_id')
            ->where('supplier_skus.sku_id', $skuId)
            ->where('supplier_skus.is_primary', true)
            ->select([
                'supplier_skus.minimum_order_qty',
                'supplier_skus.order_multiple',
                DB::raw('COALESCE(supplier_skus.expected_lead_time_days, suppliers.default_lead_time_days) as lead_time_days'),
            ])
            ->first();

        if ($supplier === null || $supplier->lead_time_days === null) {
            return $this->ageingOverstockRecommendation($base, $availableQty);
        }

        $leadTimeDays = (int) $supplier->lead_time_days;

        $expectedLeadTimeDemand = $dailyRate * $leadTimeDays;
        $safetyStock = $this->dynamicSafetyStock($warehouseId, $skuId, $dailyRate, $leadTimeDays);
        $reorderPoint = (int) ceil($expectedLeadTimeDemand + $safetyStock);

        $inventoryPosition = (int) $inventory->on_hand_qty + (int) $inventory->incoming_qty - (int) $inventory->reserved_qty;

        $daysUntilRopBreach = $inventoryPosition > $reorderPoint
            ? (int) floor(($inventoryPosition - $reorderPoint) / $dailyRate)
            : 0;

        if ($daysUntilRopBreach > $leadTimeDays) {
            return $this->ageingOverstockRecommendation($base, $availableQty);
        }

        $cautious = $this->isElevatedAgeingOrOverstock($base['ageing_risk'], $base['overstock_risk']);
        $orderMultiple = max(1, (int) $supplier->order_multiple);

        // A cautious pair still gets purchased if truly urgent, but only
        // enough to cover the reorder point itself — no review-period
        // buffer on top, since piling on more stock is exactly what an
        // elevated ageing/overstock signal argues against.
        $targetPosition = $cautious
            ? (float) $reorderPoint
            : $reorderPoint + ($dailyRate * self::REVIEW_PERIOD_DAYS);
        $rawQty = max(0.0, $targetPosition - $inventoryPosition);

        $recommendedQty = $rawQty <= 0.0
            ? 0
            : max((int) $supplier->minimum_order_qty, (int) (ceil($rawQty / $orderMultiple) * $orderMultiple));

        if ($recommendedQty <= 0) {
            return $this->ageingOverstockRecommendation($base, $availableQty);
        }

        $daysUntilStockout = (int) floor($availableQty / $dailyRate);

        $stockoutRisk = match (true) {
            $daysUntilStockout <= $leadTimeDays => StockoutRisk::Critical,
            $inventoryPosition <= $reorderPoint => StockoutRisk::Moderate,
            default => StockoutRisk::None,
        };

        $recommendationType = $cautious ? RecommendationType::ReducePurchase : RecommendationType::Purchase;

        return [...$base,
            'recommendation_type' => $recommendationType,
            'recommended_qty' => $recommendedQty,
            'recommended_action_date' => now()->addDays($daysUntilRopBreach)->toDateString(),
            'stockout_risk' => $stockoutRisk,
            'reason' => $cautious
                ? $this->buildReducePurchaseReason($daysUntilStockout, $leadTimeDays, $base['ageing_risk'], $base['overstock_risk'])
                : $this->buildReason($daysUntilStockout, $leadTimeDays, $inventoryPosition, $reorderPoint),
            // Transient — the raw pre-MOQ/pre-order-multiple shortfall,
            // read by matchTransferOpportunities() since a transfer isn't
            // bound by a supplier's pack size. Stripped before persisting.
            '_raw_need_qty' => $rawQty,
        ];
    }

    /**
     * The non-purchase half of app_plan.md §84: a pair that isn't (or is no
     * longer) urgent enough to buy more of, evaluated purely on ageing/
     * overstock risk. Returns null when neither concern applies — there's
     * simply nothing notable to flag this run, the same as Phase 7's plain
     * "not urgent" skip.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function ageingOverstockRecommendation(array $base, float $availableQty): ?array
    {
        if ($availableQty <= 0.0) {
            return null;
        }

        $ageingRisk = $base['ageing_risk'];
        $overstockRisk = $base['overstock_risk'];
        $sellThroughPercent = $this->sellThroughPercent($base['forecast_90d'] !== null ? (float) $base['forecast_90d'] : 0.0, $availableQty);

        $clearanceWorthy = $ageingRisk !== null
            && $ageingRisk >= self::AGEING_RISK_CLEARANCE_THRESHOLD
            && $sellThroughPercent !== null
            && $sellThroughPercent < self::LOW_SELL_THROUGH_PERCENT;

        if ($clearanceWorthy) {
            return [...$base,
                'recommendation_type' => RecommendationType::Clearance,
                'recommended_qty' => 0,
                'recommended_action_date' => now()->toDateString(),
                'stockout_risk' => null,
                'reason' => "High ageing risk ({$ageingRisk}/100) and low projected sell-through ({$sellThroughPercent}% over ".self::AGEING_HORIZON_DAYS.' days) — consider clearance pricing to move this stock before it becomes dead inventory.',
            ];
        }

        if ($this->isElevatedAgeingOrOverstock($ageingRisk, $overstockRisk)) {
            return [...$base,
                'recommendation_type' => RecommendationType::DoNotReorder,
                'recommended_qty' => 0,
                'recommended_action_date' => now()->toDateString(),
                'stockout_risk' => null,
                'reason' => $this->buildDoNotReorderReason($ageingRisk, $overstockRisk),
            ];
        }

        return null;
    }

    private function isElevatedAgeingOrOverstock(?int $ageingRisk, ?OverstockRisk $overstockRisk): bool
    {
        return ($ageingRisk !== null && $ageingRisk >= self::AGEING_RISK_DO_NOT_REORDER_THRESHOLD)
            || ($overstockRisk !== null && $overstockRisk !== OverstockRisk::None);
    }

    /**
     * app_plan.md §49's ageing risk score (0–100), averaging two of the
     * eight listed inputs this codebase can actually compute honestly:
     * how old the stock already is (from real `inventory_batches`), and
     * how little future demand exists relative to current stock (from the
     * real forecast). "Product lifecycle," "replacement pressure",
     * "season" and "recent price changes" aren't computable from anything
     * this schema tracks and are deliberately not fabricated — a narrower,
     * honest version of §49's fuller signal list, not the whole thing.
     */
    private function ageingRiskScore(int $warehouseId, int $skuId, float $projectedDemand, float $availableQty): ?int
    {
        $weightedAgeDays = $this->weightedBatchAge($warehouseId, $skuId);

        $demandComponent = $availableQty > 0.0
            ? max(0.0, 100 - min(100.0, ($projectedDemand / $availableQty) * 100))
            : 0.0;

        if ($weightedAgeDays === null) {
            return (int) round($demandComponent);
        }

        $ageComponent = min(100.0, ($weightedAgeDays / 365) * 100);

        return (int) round(($ageComponent + $demandComponent) / 2);
    }

    /**
     * Same weighted-average-of-remaining-batches shape as
     * `InventoryAnalyticsService::weightedAgeByPair()` (§1g), recomputed
     * here rather than shared — that method takes a whole filtered report's
     * worth of pairs at once, not a single-pair lookup, and duplicating a
     * dozen lines of aggregation was judged simpler than reshaping an
     * already-shipped, already-tested report Service around this one's
     * needs. Returns null when the pair has no open batches to age at all
     * (predates batch tracking, or genuinely has none) — see §1e's
     * "best-effort" FIFO caveat.
     */
    private function weightedBatchAge(int $warehouseId, int $skuId): ?float
    {
        $batches = DB::table('inventory_batches')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('remaining_qty', '>', 0)
            ->select(['remaining_qty', 'received_date'])
            ->get();

        $totalQty = $batches->sum('remaining_qty');

        if ($totalQty <= 0) {
            return null;
        }

        $weightedDays = $batches->sum(fn ($row) => $row->remaining_qty * abs(now()->diffInDays($row->received_date)));

        return $weightedDays / $totalQty;
    }

    /**
     * app_plan.md §50's "months of stock" overstock prediction, reduced to
     * three risk tiers matching {@see StockoutRisk}'s shape. Zero forecasted
     * demand with real stock on hand is treated as the worst case (Critical)
     * — dead stock with nothing projected to move it at all.
     */
    private function overstockRisk(float $dailyRate, float $availableQty): ?OverstockRisk
    {
        if ($availableQty <= 0.0) {
            return null;
        }

        if ($dailyRate <= 0.0) {
            return OverstockRisk::Critical;
        }

        $monthsOfStock = $availableQty / ($dailyRate * 30);

        return match (true) {
            $monthsOfStock >= self::OVERSTOCK_MONTHS_CRITICAL => OverstockRisk::Critical,
            $monthsOfStock >= self::OVERSTOCK_MONTHS_MODERATE => OverstockRisk::Moderate,
            default => OverstockRisk::None,
        };
    }

    /**
     * The percentage of current available stock expected to sell within
     * {@see AGEING_HORIZON_DAYS} — app_plan.md §50's sell-through estimate,
     * capped at 100%.
     */
    private function sellThroughPercent(float $projectedDemand, float $availableQty): ?int
    {
        if ($availableQty <= 0.0) {
            return null;
        }

        return (int) round(min(100.0, ($projectedDemand / $availableQty) * 100));
    }

    /**
     * Classic safety-stock formula (z × demand std-dev × √lead-time),
     * assuming demand variability dominates and ignoring supplier lead-time
     * variability — app_plan.md §44's "basic version," matching
     * app_architecture.md §1g's reorder point precedent. Falls back to a
     * flat number of safety days when there isn't enough history (fewer
     * than two data points) to compute a standard deviation from.
     */
    private function dynamicSafetyStock(int $warehouseId, int $skuId, float $dailyRate, int $leadTimeDays): float
    {
        $dailyQuantities = DB::table('inventory_daily_snapshots')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->whereDate('snapshot_date', '>=', now()->subDays(self::DEMAND_STD_DEV_LOOKBACK_DAYS)->toDateString())
            ->pluck('sold_qty')
            ->map(fn ($qty) => (float) $qty);

        if ($dailyQuantities->count() < 2) {
            return $dailyRate * self::FALLBACK_SAFETY_DAYS;
        }

        $mean = $dailyQuantities->avg();
        $variance = $dailyQuantities->sum(fn ($qty) => ($qty - $mean) ** 2) / ($dailyQuantities->count() - 1);
        $stdDev = sqrt($variance);

        if ($stdDev <= 0.0) {
            return $dailyRate * self::FALLBACK_SAFETY_DAYS;
        }

        return self::SERVICE_LEVEL_Z * $stdDev * sqrt($leadTimeDays);
    }

    private function buildReason(int $daysUntilStockout, int $leadTimeDays, int $inventoryPosition, int $reorderPoint): string
    {
        if ($daysUntilStockout <= $leadTimeDays) {
            return "Critical: expected stockout in {$daysUntilStockout} day(s), but the supplier's lead time is {$leadTimeDays} day(s) — ordering now still risks running out before new stock arrives.";
        }

        return "Inventory position ({$inventoryPosition}) is projected to reach the reorder point ({$reorderPoint}) within the supplier's {$leadTimeDays}-day lead time.";
    }

    private function buildReducePurchaseReason(int $daysUntilStockout, int $leadTimeDays, ?int $ageingRisk, ?OverstockRisk $overstockRisk): string
    {
        $risk = $ageingRisk !== null && (
            $overstockRisk === null || $ageingRisk >= self::AGEING_RISK_DO_NOT_REORDER_THRESHOLD
        )
            ? "ageing risk {$ageingRisk}/100"
            : 'elevated overstock risk ('.($overstockRisk?->label() ?? 'Moderate').')';

        return "Still needs restocking within the {$leadTimeDays}-day lead time (expected stockout in {$daysUntilStockout} day(s)), but {$risk} on the current stock argues against ordering the usual review-period buffer — this covers only the reorder point itself.";
    }

    private function buildDoNotReorderReason(?int $ageingRisk, ?OverstockRisk $overstockRisk): string
    {
        if ($ageingRisk !== null && $ageingRisk >= self::AGEING_RISK_DO_NOT_REORDER_THRESHOLD) {
            return "Ageing risk is {$ageingRisk}/100 — this stock is aged and/or has too little projected demand to justify buying more right now.";
        }

        return 'Overstock risk is '.($overstockRisk?->label() ?? 'elevated').' — projected months of stock on hand is high enough that buying more isn\'t warranted right now.';
    }

    /**
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return array_map(
            fn (RecommendationStatus $status) => $status->value,
            array_filter(RecommendationStatus::cases(), fn (RecommendationStatus $status) => $status->isOpen()),
        );
    }

    /**
     * @param  list<int>|null  $warehouseIds
     * @return array<int, array{warehouse_id: int, sku_id: int}>
     */
    private function pairsInScope(?array $warehouseIds): array
    {
        return DB::table('inventories')
            ->when($warehouseIds, fn ($query, $ids) => $query->whereIn('warehouse_id', $ids))
            ->select(['warehouse_id', 'sku_id'])
            ->distinct()
            ->get()
            ->map(fn ($row) => ['warehouse_id' => (int) $row->warehouse_id, 'sku_id' => (int) $row->sku_id])
            ->all();
    }

    /**
     * app_plan.md §85's "central allocation optimization," read as: someone
     * placing one consolidated supplier order across several warehouses
     * needs to see the combined need and how it splits by location, rather
     * than working through each warehouse's `Purchase`/`ReducePurchase` row
     * one at a time. This is a **real aggregation of already-computed
     * numbers, not a new optimization formula** — `recommended_qty` per
     * warehouse comes straight from {@see buildRecommendation()}'s own
     * reorder-point math; this method only groups and sums it by SKU. It
     * does not account for warehouse capacity, transport cost, or per-
     * warehouse service-level trade-offs — none of which app_plan.md
     * specifies a formula for either.
     *
     * Only SKUs with an **open** (`New`/`Reviewed`) `Purchase`/
     * `ReducePurchase` recommendation in **two or more** warehouses are
     * included — a SKU only needed in one place has nothing to allocate
     * across locations, it just needs an ordinary purchase.
     *
     * @param  array<string, mixed>  $filters
     */
    public function centralAllocation(array $filters = []): LengthAwarePaginator
    {
        $rows = $this->model
            ->newQuery()
            ->with(['warehouse:id,name', 'sku:id,sku,product_id', 'sku.product:id,name'])
            ->whereIn('status', $this->openStatuses())
            ->whereIn('recommendation_type', [RecommendationType::Purchase->value, RecommendationType::ReducePurchase->value])
            ->get()
            ->groupBy('sku_id')
            ->filter(fn ($group) => $group->pluck('warehouse_id')->unique()->count() >= 2)
            ->map(fn ($group) => (object) [
                'sku_id' => (int) $group->first()->sku_id,
                'sku' => $group->first()->sku?->sku,
                'product_name' => $group->first()->sku?->product?->name,
                'warehouse_count' => $group->pluck('warehouse_id')->unique()->count(),
                'total_recommended_qty' => (int) $group->sum('recommended_qty'),
                'breakdown' => $group->map(fn ($row) => [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'warehouse_name' => $row->warehouse?->name,
                    'recommended_qty' => (int) $row->recommended_qty,
                    'stockout_risk' => $row->stockout_risk?->value,
                ])->values()->all(),
            ])
            ->values();

        if ($search = $filters['search'] ?? null) {
            $rows = $rows->filter(fn ($row) => str_contains(strtolower((string) $row->sku), strtolower($search))
                || str_contains(strtolower((string) $row->product_name), strtolower($search)));
        }

        $rows = $rows->sortByDesc('total_recommended_qty')->values();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = $perPage > 0 ? $perPage : 20;
        $page = (int) ($filters['page'] ?? 1);
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
