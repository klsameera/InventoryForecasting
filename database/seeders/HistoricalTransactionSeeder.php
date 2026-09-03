<?php

namespace Database\Seeders;

use App\Enums\PromotionDiscountType;
use App\Models\Category;
use App\Models\Promotion;
use App\Models\Sku;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates three years of internally-consistent historical activity for
 * the catalog {@see RealCatalogSeeder} creates: purchase orders, goods
 * receipts, FIFO inventory batches, the full stock-movement ledger, daily
 * snapshots, weekly sales orders, and a handful of real past promotions.
 *
 * **Why a from-scratch replay instead of calling the real Services
 * thousands of times**: `GoodsReceiptService`/`StockMovementService`/
 * `SalesOrderService` are the source of truth for what a *correct* end
 * state looks like — this seeder's formulas below are each copied directly
 * from the Service they mirror (weighted-average cost, FIFO batch
 * consumption oldest-first, on-hand/available-qty bookkeeping) — but
 * calling those Services once per historical event, for several hundred
 * warehouse/SKU pairs over ~1,100 days, would mean hundreds of thousands of
 * individual DB round trips. This seeder computes the same end state
 * entirely in PHP memory, then bulk-inserts it. Explicit primary keys are
 * assigned from each table's current `MAX(id)` and incremented locally
 * rather than round-tripping per insert — safe here because this seeder
 * runs solo against an otherwise-idle database, not under concurrent
 * writes.
 *
 * **Demand comes from an explicit, documented causal model**, not a flat
 * random draw. Six factors multiply together: a per-SKU base rate, an
 * annual seasonal sinusoid (hot-season categories only), a day-of-week
 * retail curve, a Sri Lankan festival/payday calendar, constant-elasticity
 * price response, and a promotion lift driven by each promotion's own
 * discount percentage. Only the final `jitter()`/`poissonish()` draw is
 * random — every structural factor is deterministic and reproducible.
 *
 * **Promotions are planned before the sales loop runs** and genuinely
 * raise demand inside their own window, with a post-promotion dip for
 * pulled-forward purchases. This reverses this seeder's original choice to
 * seed promotions independently of demand (see `docs/task_log.md`). That
 * choice was made to avoid rigging `Promotion::impact()` into showing a
 * favourable result, and it succeeded — but it also meant the promotion
 * flag had *zero* correlation with demand, which teaches a covariate-aware
 * forecasting model that promotions do not matter: the opposite of the
 * real-world truth, and fatal to the whole point of training one.
 * `Promotion::impact()` is unchanged and still measures only what the data
 * genuinely contains — the lift is *modelled into the world*, not written
 * into the report.
 */
class HistoricalTransactionSeeder extends Seeder
{
    private const YEARS_OF_HISTORY = 3;

    private const REVIEW_PERIOD_DAYS = 45;

    private const SAFETY_DAYS = 10;

    private const SEASONAL_CATEGORIES = ['Air Conditioners', 'Fans', 'Air Coolers'];

    private const INSERT_CHUNK = 3000;

    /**
     * Retail weekly rhythm, Sunday-first to match {@see CarbonInterface::dayOfWeek}.
     * Deliberately sums to exactly 7.0, so applying it redistributes demand
     * within the week without changing total volume.
     *
     * @var array<int, float>
     */
    private const DAY_OF_WEEK_FACTORS = [
        0 => 1.14, // Sunday
        1 => 0.78, // Monday
        2 => 0.76, // Tuesday
        3 => 0.84, // Wednesday
        4 => 0.92, // Thursday
        5 => 1.14, // Friday
        6 => 1.42, // Saturday
    ];

    /**
     * Lift multiplier per unit of discount fraction: a 20% promotion
     * produces a 1 + (0.20 × 4.0) = 1.8× demand lift while it runs.
     */
    private const PROMOTION_LIFT_ELASTICITY = 4.0;

    /** Days after a promotion ends over which pulled-forward demand is repaid. */
    private const PROMOTION_PAYBACK_DAYS = 10;

    private const PROMOTION_PAYBACK_FACTOR = 0.78;

    /** Constant price elasticity of demand — negative, so cheaper sells more. */
    private const PRICE_ELASTICITY = -1.2;

    private int $nextPoId;

    private int $nextPoItemId;

    private int $nextGrId;

    private int $nextGrItemId;

    private int $nextBatchId;

    private int $nextSoId;

    private int $nextSoItemId;

    private CarbonInterface $historyStart;

    private CarbonInterface $historyEnd;

    /** @var array<int, string> warehouse_id => 'big'|'small', for demand-size weighting */
    private array $warehouseTier = [];

    /**
     * Promotion windows, built *before* any demand is generated so each one
     * can actually lift demand inside its own window. Persisted to the
     * `promotions` table afterwards by {@see seedPromotions()}.
     *
     * @var list<array{name: string, start: CarbonInterface, end: CarbonInterface, discount: int, sku_ids: array<int, true>}>
     */
    private array $promotionPlan = [];

    public function run(): void
    {
        $catalog = (new RealCatalogSeeder)->seedCatalog();

        /** @var list<Warehouse> $warehouses */
        $warehouses = array_values($catalog['warehouses']);
        $skuEntries = $catalog['skus'];

        $this->historyEnd = now()->startOfDay()->subDay();
        $this->historyStart = $this->historyEnd->copy()->subYears(self::YEARS_OF_HISTORY);

        $this->seedIdCounters();
        $this->classifyWarehouseTiers($warehouses);

        // Must precede the demand loop below: promotions are an *input* to
        // demand generation now, not an afterthought recorded alongside it.
        $this->buildPromotionPlan($skuEntries);

        $categoriesById = Category::query()->pluck('name', 'id');
        $supplierBySku = DB::table('supplier_skus')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_skus.supplier_id')
            ->select(['supplier_skus.sku_id', 'supplier_skus.supplier_id', 'supplier_skus.expected_lead_time_days', 'suppliers.default_lead_time_days'])
            ->get()
            ->keyBy('sku_id');

        $poRows = [];
        $poItemRows = [];
        $grRows = [];
        $grItemRows = [];
        $batchRows = [];
        $movementRows = [];
        $snapshotRows = [];
        $inventoryRows = [];

        // warehouse_id => week_index (0-based from historyStart) => sku_id => ['qty'=>int,'revenue'=>float,'cost'=>float]
        $weeklySales = [];

        $totalPairs = 0;

        foreach ($skuEntries as $entry) {
            /** @var Sku $sku */
            $sku = $entry['sku'];
            $product = $sku->product;
            $categoryName = $categoriesById[$product->category_id] ?? '';
            $supplierInfo = $supplierBySku[$sku->id];
            $leadTimeDays = (int) ($supplierInfo->expected_lead_time_days ?? $supplierInfo->default_lead_time_days);

            foreach ($this->assignWarehouses($warehouses, $sku->id) as $warehouse) {
                $totalPairs++;

                $this->generatePair(
                    warehouse: $warehouse,
                    sku: $sku,
                    supplierId: (int) $supplierInfo->supplier_id,
                    lifecycle: $entry['lifecycle'],
                    categoryName: $categoryName,
                    leadTimeDays: max(3, $leadTimeDays),
                    costPrice: (float) $sku->cost_price,
                    sellingPrice: (float) $sku->selling_price,
                    poRows: $poRows,
                    poItemRows: $poItemRows,
                    grRows: $grRows,
                    grItemRows: $grItemRows,
                    batchRows: $batchRows,
                    movementRows: $movementRows,
                    snapshotRows: $snapshotRows,
                    inventoryRows: $inventoryRows,
                    weeklySales: $weeklySales,
                );
            }
        }

        $this->info("Generated data for {$totalPairs} warehouse/SKU pairs.");

        $this->bulkInsert('purchase_orders', $poRows);
        $this->bulkInsert('purchase_order_items', $poItemRows);
        $this->bulkInsert('goods_receipts', $grRows);
        $this->bulkInsert('goods_receipt_items', $grItemRows);
        $this->bulkInsert('inventory_batches', $batchRows);
        $this->bulkInsert('stock_movements', $movementRows);
        $this->bulkInsert('inventory_daily_snapshots', $snapshotRows);
        $this->bulkInsert('inventories', $inventoryRows);

        $this->info('Core ledger inserted: '
            .count($movementRows).' movements, '
            .count($snapshotRows).' daily snapshots, '
            .count($batchRows).' batches, '
            .count($poRows).' purchase orders.');

        $this->seedWeeklySalesOrders($weeklySales);
        $this->seedPromotions();
    }

    /**
     * These progress-log calls only ever run inside this class' own
     * `run()`, which only executes via Laravel's own `db:seed` command
     * resolution — `RealCatalogSeeder` is called here through its
     * `seedCatalog()` entry point instead, precisely to avoid needing its
     * `$command` (never set on a direct `new self()` instance) at all.
     */
    private function info(string $message): void
    {
        $this->command->info($message);
    }

    private function seedIdCounters(): void
    {
        $this->nextPoId = ((int) DB::table('purchase_orders')->max('id')) + 1;
        $this->nextPoItemId = ((int) DB::table('purchase_order_items')->max('id')) + 1;
        $this->nextGrId = ((int) DB::table('goods_receipts')->max('id')) + 1;
        $this->nextGrItemId = ((int) DB::table('goods_receipt_items')->max('id')) + 1;
        $this->nextBatchId = ((int) DB::table('inventory_batches')->max('id')) + 1;
        $this->nextSoId = ((int) DB::table('sales_orders')->max('id')) + 1;
        $this->nextSoItemId = ((int) DB::table('sales_order_items')->max('id')) + 1;
    }

    /**
     * @param  list<Warehouse>  $warehouses
     */
    private function classifyWarehouseTiers(array $warehouses): void
    {
        // The first three real warehouses imported (Kadolkale, Galle,
        // Dambulla — the larger regional hubs) get a "big" demand
        // multiplier; the rest ("small") get a lighter one.
        foreach ($warehouses as $index => $warehouse) {
            $this->warehouseTier[$warehouse->id] = $index < 3 ? 'big' : 'small';
        }
    }

    /**
     * @param  list<Warehouse>  $warehouses
     * @return list<Warehouse>
     */
    private function assignWarehouses(array $warehouses, int $skuId): array
    {
        mt_srand($skuId * 7919); // deterministic per SKU, still varied across SKUs
        $count = random_int(2, 4);
        $shuffled = $warehouses;
        shuffle($shuffled);
        mt_srand();

        return array_slice($shuffled, 0, $count);
    }

    /**
     * Replays one warehouse/SKU pair's entire history day by day, appending
     * rows to the shared accumulator arrays (passed by reference to avoid
     * copying large arrays per pair).
     *
     * @param  array<int, array<string, mixed>>  $poRows
     * @param  array<int, array<string, mixed>>  $poItemRows
     * @param  array<int, array<string, mixed>>  $grRows
     * @param  array<int, array<string, mixed>>  $grItemRows
     * @param  array<int, array<string, mixed>>  $batchRows
     * @param  array<int, array<string, mixed>>  $movementRows
     * @param  array<int, array<string, mixed>>  $snapshotRows
     * @param  array<int, array<string, mixed>>  $inventoryRows
     * @param  array<int, array<int, array<int, array{qty: int, revenue: float, cost: float}>>>  $weeklySales
     */
    private function generatePair(
        Warehouse $warehouse,
        Sku $sku,
        int $supplierId,
        string $lifecycle,
        string $categoryName,
        int $leadTimeDays,
        float $costPrice,
        float $sellingPrice,
        array &$poRows,
        array &$poItemRows,
        array &$grRows,
        array &$grItemRows,
        array &$batchRows,
        array &$movementRows,
        array &$snapshotRows,
        array &$inventoryRows,
        array &$weeklySales,
    ): void {
        $product = $sku->product;
        $tierMultiplier = $this->warehouseTier[$warehouse->id] === 'big' ? 1.3 : 0.7;
        $seasonal = in_array($categoryName, self::SEASONAL_CATEGORIES, true);
        $baseRate = $this->baseRateForPrice($sellingPrice) * $tierMultiplier;

        $launchDate = Carbon::parse($product->launch_date);
        if ($launchDate->lt($this->historyStart)) {
            $launchDate = Carbon::parse($this->historyStart->toDateString());
        }
        $eolDate = $product->end_of_life_date ? Carbon::parse($product->end_of_life_date) : null;
        $declineStart = $this->historyEnd->copy()->subDays(120);
        $priceCurve = $this->buildPriceCurve($sellingPrice);

        $onHand = 0;
        $averageCost = $costPrice;
        /** @var list<array{remaining: int, unit_cost: float, row_index: int}> $batches */
        $batches = [];
        $pendingReceipts = []; // 'Y-m-d' => list of {qty, unit_cost, po_id, po_item_id}
        $inventoryIncoming = 0;

        // Opening stock at launch — predates batch tracking on purpose,
        // matching app_architecture.md §1e's real "best-effort FIFO" caveat
        // for stock that existed before this ledger did.
        $openingQty = max(4, (int) round($baseRate * 30));
        $onHand = $openingQty;
        $movementRows[] = $this->movementRow($warehouse->id, $sku->id, 'OPENING_STOCK', $openingQty, $averageCost, $launchDate->copy()->setTime(8, 0), null, null);

        $nextReviewDate = $launchDate->copy();
        $cursor = $launchDate->copy();

        while ($cursor->lte($this->historyEnd)) {
            $dateKey = $cursor->toDateString();
            $openingQtyForDay = $onHand;
            $receivedQtyForDay = 0;
            $soldQtyForDay = 0;
            $adjustmentQtyForDay = 0;
            $stockoutFlag = false;
            $stockoutMinutes = null;

            $priceOnDay = $this->priceAt($priceCurve, $cursor);
            $rate = $this->dailyRate(
                baseRate: $baseRate,
                day: $cursor,
                seasonal: $seasonal,
                lifecycle: $lifecycle,
                launchDate: $launchDate,
                declineStart: $declineStart,
                eolDate: $eolDate,
                skuId: $sku->id,
                priceFactor: $this->priceElasticityFactor($priceOnDay, $sellingPrice),
            );
            $stillPurchasable = $eolDate === null || $cursor->lt($eolDate);

            // Purchasing decision: the periodic review below re-evaluates
            // every ~45 days regardless of stock level, which alone lets a
            // pair run dry for weeks between reviews if demand outpaces the
            // plan. A continuous reorder-point check closes that gap — if
            // stock has already fallen to roughly lead-time-plus-a-margin
            // of demand and nothing is already inbound, order early rather
            // than waiting for the next scheduled review.
            $reorderPoint = $rate * $leadTimeDays * 1.5;
            $needsEarlyReorder = $pendingReceipts === [] && $onHand <= $reorderPoint;

            if ($stillPurchasable && ($cursor->gte($nextReviewDate) || $needsEarlyReorder)) {
                $target = (int) ceil($rate * (self::REVIEW_PERIOD_DAYS + $leadTimeDays + self::SAFETY_DAYS));
                $orderQty = max(0, $target - $onHand);

                if ($orderQty > 0) {
                    $actualLeadTime = max(3, (int) round($leadTimeDays * $this->jitter(0.8, 1.3)));
                    $expectedDate = $cursor->copy()->addDays($leadTimeDays);
                    $receivedDate = $cursor->copy()->addDays($actualLeadTime);
                    $unitCost = round($costPrice * $this->costInflationFactor($cursor), 2);
                    $stillOpen = $receivedDate->gt($this->historyEnd);

                    $poId = $this->nextPoId++;
                    $poItemId = $this->nextPoItemId++;
                    $lineTotal = round($orderQty * $unitCost, 2);

                    $poRows[] = [
                        'id' => $poId,
                        'po_number' => 'PO-'.str_pad((string) $poId, 6, '0', STR_PAD_LEFT),
                        'supplier_id' => $supplierId,
                        'warehouse_id' => $warehouse->id,
                        'status' => $stillOpen ? 'ordered' : 'received',
                        'order_date' => $dateKey,
                        'expected_date' => $expectedDate->toDateString(),
                        'notes' => null,
                        'subtotal' => $lineTotal,
                        'tax' => 0,
                        'total' => $lineTotal,
                        'created_by' => null,
                        'created_at' => $cursor->toDateTimeString(),
                        'updated_at' => $cursor->toDateTimeString(),
                        'deleted_at' => null,
                    ];
                    $poItemRows[] = [
                        'id' => $poItemId,
                        'purchase_order_id' => $poId,
                        'sku_id' => $sku->id,
                        'quantity' => $orderQty,
                        'unit_cost' => $unitCost,
                        'received_qty' => $stillOpen ? 0 : $orderQty,
                        'line_total' => $orderQty * $unitCost,
                        'created_at' => $cursor->toDateTimeString(),
                        'updated_at' => $cursor->toDateTimeString(),
                    ];

                    if ($stillOpen) {
                        // Last cycle's order hasn't arrived by the end of
                        // history — real incoming_qty for the Recommendation
                        // engine to see, matching a genuinely in-transit PO.
                        $inventoryIncoming += $orderQty;
                    } else {
                        $pendingReceipts[$receivedDate->toDateString()][] = [
                            'qty' => $orderQty,
                            'unit_cost' => $unitCost,
                            'po_id' => $poId,
                            'po_item_id' => $poItemId,
                        ];
                    }
                }

                $nextReviewDate = $cursor->copy()->addDays((int) round(self::REVIEW_PERIOD_DAYS * $this->jitter(0.85, 1.15)));
            }

            // Apply any receipts landing today.
            foreach ($pendingReceipts[$dateKey] ?? [] as $receipt) {
                $grId = $this->nextGrId++;
                $grItemId = $this->nextGrItemId++;
                $batchId = $this->nextBatchId++;

                $grRows[] = [
                    'id' => $grId,
                    'receipt_number' => 'GR-'.str_pad((string) $grId, 6, '0', STR_PAD_LEFT),
                    'purchase_order_id' => $receipt['po_id'],
                    'warehouse_id' => $warehouse->id,
                    'received_date' => $dateKey,
                    'notes' => null,
                    'received_by' => null,
                    'created_at' => $cursor->toDateTimeString(),
                ];
                $grItemRows[] = [
                    'id' => $grItemId,
                    'goods_receipt_id' => $grId,
                    'purchase_order_item_id' => $receipt['po_item_id'],
                    'sku_id' => $sku->id,
                    'received_qty' => $receipt['qty'],
                    'unit_cost' => $receipt['unit_cost'],
                    'created_at' => $cursor->toDateTimeString(),
                ];
                $batchRows[] = [
                    'id' => $batchId,
                    'warehouse_id' => $warehouse->id,
                    'sku_id' => $sku->id,
                    'source_type' => 'goods_receipt',
                    'source_id' => $grId,
                    'received_date' => $dateKey,
                    'received_qty' => $receipt['qty'],
                    'remaining_qty' => $receipt['qty'],
                    'unit_cost' => $receipt['unit_cost'],
                    'expiry_date' => null,
                    'created_at' => $cursor->toDateTimeString(),
                    'updated_at' => $cursor->toDateTimeString(),
                ];
                $batches[] = ['remaining' => $receipt['qty'], 'unit_cost' => $receipt['unit_cost'], 'row_index' => count($batchRows) - 1];

                $averageCost = $onHand > 0
                    ? round((($onHand * $averageCost) + ($receipt['qty'] * $receipt['unit_cost'])) / ($onHand + $receipt['qty']), 2)
                    : $receipt['unit_cost'];
                $onHand += $receipt['qty'];
                $receivedQtyForDay += $receipt['qty'];

                $movementRows[] = $this->movementRow($warehouse->id, $sku->id, 'PURCHASE_RECEIPT', $receipt['qty'], $receipt['unit_cost'], $cursor->copy()->setTime(9, 0), 'goods_receipt', $grId);
            }
            unset($pendingReceipts[$dateKey]);

            // Sales for the day.
            $desired = $this->poissonish($rate);
            $sold = min($desired, $onHand);

            if ($onHand <= 0 && $rate > 0.0) {
                $stockoutFlag = true;
                $stockoutMinutes = 1440;
            } elseif ($desired > $onHand) {
                $stockoutFlag = true;
                $stockoutMinutes = random_int(60, 600);
            }

            if ($sold > 0) {
                $weightedCost = $this->consumeFifo($batches, $batchRows, $sold);
                $onHand -= $sold;
                $soldQtyForDay = $sold;
                $movementRows[] = $this->movementRow($warehouse->id, $sku->id, 'SALE', $sold, $weightedCost, $cursor->copy()->setTime(14, 0), null, null);

                $weekIndex = (int) floor(abs($this->historyStart->diffInDays($cursor)) / 7);
                $unitCostForRevenue = $weightedCost ?? $averageCost;

                if (! isset($weeklySales[$warehouse->id][$weekIndex][$sku->id])) {
                    $weeklySales[$warehouse->id][$weekIndex][$sku->id] = ['qty' => 0, 'revenue' => 0.0, 'cost' => 0.0];
                }

                $weeklySales[$warehouse->id][$weekIndex][$sku->id]['qty'] += $sold;
                $weeklySales[$warehouse->id][$weekIndex][$sku->id]['revenue'] += $sold * $priceOnDay;
                $weeklySales[$warehouse->id][$weekIndex][$sku->id]['cost'] += $sold * $unitCostForRevenue;
            }

            // A rare damage/write-off event for realistic texture.
            if ($onHand > 3 && random_int(1, 1000) <= 6) {
                $adjQty = random_int(1, min(3, $onHand));
                $type = random_int(0, 1) === 0 ? 'DAMAGE' : 'WRITE_OFF';
                $this->consumeFifo($batches, $batchRows, $adjQty);
                $onHand -= $adjQty;
                $adjustmentQtyForDay -= $adjQty;
                $movementRows[] = $this->movementRow($warehouse->id, $sku->id, $type, $adjQty, null, $cursor->copy()->setTime(16, 0), null, null);
            }

            $snapshotRows[] = [
                'snapshot_date' => $dateKey,
                'warehouse_id' => $warehouse->id,
                'sku_id' => $sku->id,
                'opening_qty' => $openingQtyForDay,
                'received_qty' => $receivedQtyForDay,
                'sold_qty' => $soldQtyForDay,
                'returned_qty' => 0,
                'transfer_in_qty' => 0,
                'transfer_out_qty' => 0,
                'adjustment_qty' => $adjustmentQtyForDay,
                'closing_qty' => $onHand,
                'available_qty' => max(0, $onHand),
                'stockout_minutes' => $stockoutMinutes,
                'stockout_flag' => $stockoutFlag,
                'inventory_value' => round($onHand * $averageCost, 2),
                'created_at' => $cursor->toDateTimeString(),
                'updated_at' => $cursor->toDateTimeString(),
            ];

            $cursor->addDay();
        }

        $inventoryRows[] = [
            'warehouse_id' => $warehouse->id,
            'sku_id' => $sku->id,
            'on_hand_qty' => max(0, $onHand),
            'reserved_qty' => 0,
            'available_qty' => max(0, $onHand),
            'incoming_qty' => $inventoryIncoming,
            'average_cost' => $averageCost,
            'created_at' => $this->historyStart->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    private function baseRateForPrice(float $price): float
    {
        $rate = 1.6 / (1 + $price / 15000);
        $rate = max(0.03, min(1.4, $rate));

        // Per-SKU variety: some fast movers, some slow, within the same
        // price tier, so not every SKU at a given price point behaves
        // identically.
        return $rate * $this->jitter(0.6, 1.6);
    }

    /**
     * The demand model. Every factor below multiplies the per-SKU base
     * rate; only the closing `jitter()` is random. `$priceFactor` is passed
     * in rather than computed here because the caller already resolves the
     * day's price for revenue capture.
     */
    private function dailyRate(
        float $baseRate,
        Carbon $day,
        bool $seasonal,
        string $lifecycle,
        Carbon $launchDate,
        CarbonInterface $declineStart,
        ?Carbon $eolDate,
        int $skuId,
        float $priceFactor,
    ): float {
        $rate = $baseRate;

        if ($seasonal) {
            $dayOfYear = (int) $day->dayOfYear;
            // Sri Lanka's hot season roughly March-September; sinusoidal
            // peak around mid-year.
            $seasonalFactor = 1.0 + 0.5 * sin((($dayOfYear - 75) / 365) * 2 * M_PI);
            $rate *= max(0.4, $seasonalFactor);
        }

        if ($lifecycle === 'new') {
            $daysSinceLaunch = abs($launchDate->diffInDays($day));

            if ($daysSinceLaunch < 30) {
                $rate *= 0.3 + (0.7 * ($daysSinceLaunch / 30));
            }
        }

        if ($lifecycle === 'declining' && $day->gte($declineStart)) {
            $daysIntoDecline = abs($declineStart->diffInDays($day));
            $declineWindow = max(1, abs($declineStart->diffInDays($this->historyEnd)));
            $progress = min(1.0, $daysIntoDecline / $declineWindow);
            $rate *= max(0.1, 1 - (0.85 * $progress));
        }

        if ($lifecycle === 'end_of_life') {
            if ($eolDate !== null && $day->gte($eolDate)) {
                return 0.0;
            }

            $windowStart = $eolDate !== null ? $eolDate->copy()->subDays(90) : $declineStart;

            if ($day->gte($windowStart)) {
                $daysIn = abs($windowStart->diffInDays($day));
                $window = $eolDate !== null ? max(1, abs($windowStart->diffInDays($eolDate))) : 90;
                $progress = min(1.0, $daysIn / $window);
                $rate *= max(0.05, 1 - (0.9 * $progress));
            }
        }

        $rate *= $this->dayOfWeekFactor($day);
        $rate *= $this->calendarEventFactor($day);
        $rate *= $this->promotionFactor($skuId, $day);
        $rate *= $priceFactor;

        return max(0.0, $rate * $this->jitter(0.5, 1.6));
    }

    /**
     * Retail weekly rhythm. Real store demand is not flat across the week,
     * and without this the dataset contains no weekly signal whatsoever —
     * which makes a day-of-week-aware model (`seasonal_naive`, and any
     * covariate-aware neural model) mathematically incapable of beating a
     * plain moving average, no matter how well it is trained.
     */
    private function dayOfWeekFactor(Carbon $day): float
    {
        return self::DAY_OF_WEEK_FACTORS[(int) $day->dayOfWeek] ?? 1.0;
    }

    /**
     * The Sri Lankan retail calendar. The Avurudu run-up is the single
     * biggest appliance-buying window of the year; Vesak and Deepavali are
     * smaller peaks; Christmas lifts December; January is the post-festive
     * lull; and demand clusters around month-end paydays.
     */
    private function calendarEventFactor(Carbon $day): float
    {
        $factor = 1.0;
        $month = (int) $day->month;
        $dayOfMonth = (int) $day->day;
        $daysInMonth = (int) $day->daysInMonth;

        if ($month === 4 && $dayOfMonth >= 5 && $dayOfMonth <= 14) {
            $factor *= 1.9; // Sinhala & Tamil New Year run-up.
        }

        if ($month === 5 && $dayOfMonth >= 12 && $dayOfMonth <= 19) {
            $factor *= 1.35; // Vesak.
        }

        if (($month === 10 && $dayOfMonth >= 25) || ($month === 11 && $dayOfMonth <= 5)) {
            $factor *= 1.25; // Deepavali.
        }

        if ($month === 12 && $dayOfMonth >= 12 && $dayOfMonth <= 25) {
            $factor *= 1.55; // Christmas run-up.
        }

        if ($month === 1 && $dayOfMonth >= 5 && $dayOfMonth <= 25) {
            $factor *= 0.82; // Post-festive lull.
        }

        if ($dayOfMonth >= $daysInMonth - 2 || $dayOfMonth <= 3) {
            $factor *= 1.28; // Payday clustering.
        }

        return $factor;
    }

    /**
     * A promotion genuinely raises demand while it runs, then repays part
     * of that lift afterwards — customers who would have bought the
     * following week bought during the sale instead. Both halves matter for
     * training: a model that learns only the spike will over-forecast the
     * fortnight after every promotion.
     */
    private function promotionFactor(int $skuId, Carbon $day): float
    {
        foreach ($this->promotionPlan as $promotion) {
            if (! isset($promotion['sku_ids'][$skuId])) {
                continue;
            }

            if ($day->betweenIncluded($promotion['start'], $promotion['end'])) {
                return 1.0 + (($promotion['discount'] / 100) * self::PROMOTION_LIFT_ELASTICITY);
            }

            $paybackEnd = $promotion['end']->copy()->addDays(self::PROMOTION_PAYBACK_DAYS);

            if ($day->gt($promotion['end']) && $day->lte($paybackEnd)) {
                return self::PROMOTION_PAYBACK_FACTOR;
            }
        }

        return 1.0;
    }

    /**
     * Constant-elasticity price response, measured against the SKU's
     * present-day selling price. {@see buildPriceCurve()} walks backward
     * from that price, so historical days genuinely sit at different price
     * points and `/price-elasticity` has real variation to regress on.
     */
    private function priceElasticityFactor(float $priceOnDay, float $referencePrice): float
    {
        if ($priceOnDay <= 0.0 || $referencePrice <= 0.0) {
            return 1.0;
        }

        return ($priceOnDay / $referencePrice) ** self::PRICE_ELASTICITY;
    }

    private function jitter(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function costInflationFactor(Carbon $day): float
    {
        $yearsIn = abs($this->historyStart->diffInDays($day)) / 365;

        return 1.04 ** $yearsIn;
    }

    /**
     * A short list of {from, price} revision points across the window,
     * built backward from today's real selling price so the most recent
     * segment always matches the catalog's current price exactly.
     *
     * @return list<array{from: CarbonInterface, price: float}>
     */
    private function buildPriceCurve(float $currentPrice): array
    {
        $points = [['from' => $this->historyEnd->copy(), 'price' => $currentPrice]];
        $cursor = $this->historyEnd->copy();
        $price = $currentPrice;

        while (true) {
            $cursor = $cursor->copy()->subDays(random_int(270, 450));

            if ($cursor->lte($this->historyStart)) {
                break;
            }

            $price = round($price / (1 + $this->jitter(-0.12, 0.10)), 2);
            $points[] = ['from' => $cursor->copy(), 'price' => $price];
        }

        $points[] = ['from' => $this->historyStart->copy(), 'price' => $price];

        usort($points, fn ($a, $b) => $a['from'] <=> $b['from']);

        return $points;
    }

    /**
     * @param  list<array{from: CarbonInterface, price: float}>  $curve
     */
    private function priceAt(array $curve, Carbon $day): float
    {
        $price = $curve[0]['price'];

        foreach ($curve as $point) {
            if ($point['from']->gt($day)) {
                break;
            }

            $price = $point['price'];
        }

        return $price;
    }

    /**
     * A simple stochastic day-count generator — not literally Poisson, but
     * gives realistic day-to-day variability (fractional rates mostly
     * resolve to 0, occasional bursts, occasional unusually quiet days)
     * without needing a real distribution library.
     */
    private function poissonish(float $rate): int
    {
        if ($rate <= 0.0) {
            return 0;
        }

        $base = (int) floor($rate);
        $fraction = $rate - $base;

        if ((mt_rand() / mt_getrandmax()) < $fraction) {
            $base++;
        }

        if (random_int(1, 100) <= 5) {
            $base += random_int(1, 3);
        }

        if ($base > 0 && random_int(1, 100) <= 8) {
            $base = max(0, $base - random_int(1, $base));
        }

        return max(0, $base);
    }

    /**
     * FIFO-consumes `$qty` from the in-memory batch list (already ordered
     * oldest-first, matching `received_date ASC, id ASC`), updating each
     * consumed batch's `remaining_qty` in `$batchRows` by reference — the
     * same drain-don't-delete behavior `InventoryBatchFacade::consumeFifo()`
     * has. Returns the weighted-average cost of whatever was actually
     * consumed, or null if no open batches existed (best-effort — matches
     * the real Service's "predates batch tracking" tolerance).
     *
     * @param  list<array{remaining: int, unit_cost: float, row_index: int}>  $batches
     * @param  array<int, array<string, mixed>>  $batchRows
     */
    private function consumeFifo(array &$batches, array &$batchRows, int $qty): ?float
    {
        $remaining = $qty;
        $totalCost = 0.0;
        $totalConsumed = 0;

        foreach ($batches as &$batch) {
            if ($remaining <= 0) {
                break;
            }

            if ($batch['remaining'] <= 0) {
                continue;
            }

            $take = min($batch['remaining'], $remaining);
            $batch['remaining'] -= $take;
            $batchRows[$batch['row_index']]['remaining_qty'] = $batch['remaining'];
            $totalCost += $take * $batch['unit_cost'];
            $totalConsumed += $take;
            $remaining -= $take;
        }
        unset($batch);

        return $totalConsumed > 0 ? round($totalCost / $totalConsumed, 2) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(
        int $warehouseId,
        int $skuId,
        string $type,
        int $qty,
        ?float $unitCost,
        Carbon $occurredAt,
        ?string $referenceType,
        ?int $referenceId,
    ): array {
        return [
            'warehouse_id' => $warehouseId,
            'sku_id' => $skuId,
            'movement_type' => $type,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'occurred_at' => $occurredAt->toDateTimeString(),
            'user_id' => null,
            'notes' => null,
            'created_at' => $occurredAt->toDateTimeString(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function bulkInsert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * One confirmed SalesOrder per warehouse per week, with a line item per
     * SKU that actually sold there that week — a realistic consolidated
     * weekly reporting grain, not a literal one-order-per-transaction
     * replica of the daily stock_movements ledger (that would be as
     * expensive to generate as the ledger itself for no real benefit;
     * nothing in this app joins `sales_order_items` back to
     * `stock_movements` by reference).
     *
     * @param  array<int, array<int, array<int, array{qty: int, revenue: float, cost: float}>>>  $weeklySales
     */
    private function seedWeeklySalesOrders(array $weeklySales): void
    {
        $soRows = [];
        $soItemRows = [];

        foreach ($weeklySales as $warehouseId => $weeks) {
            foreach ($weeks as $weekIndex => $skuAggregates) {
                $items = [];
                $subtotal = 0.0;
                $soId = $this->nextSoId;

                foreach ($skuAggregates as $skuId => $aggregate) {
                    if ($aggregate['qty'] <= 0) {
                        continue;
                    }

                    $unitPrice = round($aggregate['revenue'] / $aggregate['qty'], 2);
                    $netAmount = round($aggregate['revenue'], 2);
                    $subtotal += $netAmount;

                    $items[] = [
                        'id' => $this->nextSoItemId++,
                        'sales_order_id' => $soId,
                        'sku_id' => $skuId,
                        'quantity' => $aggregate['qty'],
                        'unit_price' => $unitPrice,
                        'discount' => 0,
                        'net_amount' => $netAmount,
                        'cost' => round($aggregate['cost'], 2),
                    ];
                }

                if ($items === []) {
                    continue;
                }

                $this->nextSoId++;
                $orderDate = $this->historyStart->copy()->addWeeks($weekIndex)->addDays(random_int(0, 6));

                if ($orderDate->gt($this->historyEnd)) {
                    $orderDate = $this->historyEnd->copy();
                }

                $subtotal = round($subtotal, 2);

                $soRows[] = [
                    'id' => $soId,
                    'order_number' => 'SO-'.str_pad((string) $soId, 6, '0', STR_PAD_LEFT),
                    'warehouse_id' => $warehouseId,
                    'customer_name' => null,
                    'status' => 'confirmed',
                    'order_date' => $orderDate->toDateString(),
                    'subtotal' => $subtotal,
                    'discount' => 0,
                    'tax' => 0,
                    'total' => $subtotal,
                    'created_by' => null,
                    'created_at' => $orderDate->toDateTimeString(),
                    'updated_at' => $orderDate->toDateTimeString(),
                    'deleted_at' => null,
                ];

                foreach ($items as $item) {
                    $item['created_at'] = $orderDate->toDateTimeString();
                    $item['updated_at'] = $orderDate->toDateTimeString();
                    $soItemRows[] = $item;
                }
            }
        }

        $this->bulkInsert('sales_orders', $soRows);
        $this->bulkInsert('sales_order_items', $soItemRows);

        $this->info('Sales orders: '.count($soRows).' orders, '.count($soItemRows).' line items.');
    }

    /**
     * Plans the real Sri Lankan retail promotion windows this history
     * contains, each assigned to a sample of established-lifecycle SKUs.
     *
     * Each template recurs **once per year the history covers**, not once
     * overall. A covariate-aware model needs several instances of each
     * promotion type to generalise from; one instance per three-year window
     * is a single observation, which is not learnable.
     *
     * Windows always close at least 20 days before `historyEnd` so
     * `Promotion::impact()` has a fully elapsed window to report on.
     *
     * @param  list<array{sku: Sku, lifecycle: string}>  $skuEntries
     */
    private function buildPromotionPlan(array $skuEntries): void
    {
        $establishedSkuIds = array_values(array_map(
            fn ($entry) => $entry['sku']->id,
            array_filter($skuEntries, fn ($entry) => $entry['lifecycle'] === 'established'),
        ));

        if ($establishedSkuIds === []) {
            return;
        }

        $templates = [
            ['name' => 'Avurudu Home Sale', 'month' => 4, 'day' => 10, 'days' => 14],
            ['name' => 'Vesak Appliance Fest', 'month' => 5, 'day' => 15, 'days' => 10],
            ['name' => 'Black Friday Electronics', 'month' => 11, 'day' => 24, 'days' => 7],
            ['name' => 'New Year Mega Sale', 'month' => 1, 'day' => 2, 'days' => 12],
        ];

        $latestAllowedEnd = $this->historyEnd->copy()->subDays(20);

        for ($year = (int) $this->historyStart->year; $year <= (int) $this->historyEnd->year; $year++) {
            foreach ($templates as $template) {
                $start = Carbon::create($year, $template['month'], $template['day']);
                $end = $start->copy()->addDays($template['days']);

                if ($start->lt($this->historyStart) || $end->gt($latestAllowedEnd)) {
                    continue;
                }

                $selected = collect($establishedSkuIds)
                    ->random(min(8, count($establishedSkuIds)))
                    ->all();

                $this->promotionPlan[] = [
                    'name' => $template['name'].' '.$year,
                    'start' => $start,
                    'end' => $end,
                    'discount' => random_int(15, 25),
                    'sku_ids' => array_fill_keys($selected, true),
                ];
            }
        }
    }

    /**
     * Persists the plan {@see buildPromotionPlan()} already generated
     * demand from. Runs last only because it needs nothing from the ledger
     * — the demand effect was applied while the sales loop ran.
     */
    private function seedPromotions(): void
    {
        foreach ($this->promotionPlan as $planned) {
            $promotion = Promotion::create([
                'name' => $planned['name'],
                'discount_type' => PromotionDiscountType::Percentage,
                'discount_value' => $planned['discount'],
                'start_date' => $planned['start']->toDateString(),
                'end_date' => $planned['end']->toDateString(),
                'notes' => 'Seeded historical promotion.',
                'created_by' => null,
            ]);

            $promotion->skus()->sync(array_keys($planned['sku_ids']));
        }

        $this->info('Promotions: '.Promotion::count());
    }
}
