<?php

declare(strict_types=1);

namespace Domain\Services\MlTrainingDataService;

use App\Enums\PromotionDiscountType;
use Domain\Services\MlServiceClient\MlServiceClient;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exports the full modelling dataset the Python training pipeline reads
 * (`ml-service/training/`).
 *
 * **Why a file and not the `/forecast/run` HTTP contract.** {@see MlServiceClient}
 * exists to serve *inference* — a compact, capped window of prepared
 * features for a few hundred pairs, matching app_plan.md §35's warning
 * against shipping raw tables over HTTP. Training needs the opposite:
 * every day of every pair's history at once, hundreds of thousands of rows.
 * Pushing that through the same JSON endpoint would be slow, memory-hungry
 * on both sides, and would blur a boundary worth keeping sharp — so
 * training data leaves as a file on disk and the HTTP contract stays a
 * serving contract.
 *
 * Rows stream out through a cursor and are written incrementally, so memory
 * stays flat regardless of how much history the database holds.
 *
 * **Deliberately not exported: a daily price series.** The demand this
 * dataset describes genuinely responds to price (see
 * `HistoricalTransactionSeeder`'s constant-elasticity term), but no table
 * in this schema records what a SKU's selling price was on a given past
 * day — `skus.selling_price` is the current price only, and
 * `sales_order_items.unit_price` exists only on days that had a sale, at
 * weekly aggregation grain. Rather than fabricate a daily price series or
 * silently forward-fill a sparse one, price ships as a single static
 * per-SKU feature and this limitation is recorded here and in
 * `docs/app_architecture.md`.
 *
 * Like {@see MlServiceClient}, this Service has no constructor-injected
 * model — it owns no table.
 */
final class MlTrainingDataService
{
    /** Written relative to `storage/app/`. */
    public const DEFAULT_RELATIVE_PATH = 'ml/training_data.csv';

    /** This application's own stock ledger, via `inventory_daily_snapshots`. */
    public const SOURCE_LEDGER = 'ledger';

    /** Demand synced from the BuyAbans back office. */
    public const SOURCE_BUYABANS = 'buyabans';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_LEDGER, self::SOURCE_BUYABANS];

    /**
     * The configured demand source, governing both training and serving.
     *
     * Static because the serving path ({@see MlServiceClient})
     * needs the same answer without taking a dependency on this Service — the
     * two must never disagree, or a model gets trained on one distribution and
     * served another with nothing reporting an error.
     */
    public static function demandSource(): string
    {
        $source = (string) config('services.ml.demand_source', self::SOURCE_LEDGER);

        return in_array($source, self::SOURCES, true) ? $source : self::SOURCE_LEDGER;
    }

    /**
     * Column order is the training pipeline's contract — `dataset.py` reads
     * these names, so the two must change together.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'date',
        'warehouse_id',
        'sku_id',
        'category_id',
        'brand_id',
        'sold_qty',
        'opening_qty',
        'closing_qty',
        'available_qty',
        'received_qty',
        'adjustment_qty',
        'stockout_minutes',
        'stockout_flag',
        'on_promotion',
        'promotion_discount',
        'selling_price',
    ];

    /**
     * @param  string  $source  'ledger' (this application's own stock ledger) or
     *                          'buyabans' (demand synced from the back office)
     * @return array{success: bool, message: string, data?: array{path: string, rows: int, series: int, first_date: ?string, last_date: ?string}}
     */
    public function export(?string $relativePath = null, ?string $source = null): array
    {
        $source ??= self::demandSource();

        if (! in_array($source, self::SOURCES, true)) {
            return ['success' => false, 'message' => "Unknown training data source '{$source}'."];
        }

        $relativePath ??= self::DEFAULT_RELATIVE_PATH;
        $absolutePath = storage_path('app/'.$relativePath);

        try {
            $directory = dirname($absolutePath);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return ['success' => false, 'message' => "Could not create directory {$directory}"];
            }

            $handle = fopen($absolutePath, 'w');

            if ($handle === false) {
                return ['success' => false, 'message' => "Could not open {$absolutePath} for writing"];
            }

            $promotions = $this->promotionWindows();

            fputcsv($handle, self::COLUMNS);

            $rows = 0;
            $series = [];
            $firstDate = null;
            $lastDate = null;

            $query = $source === self::SOURCE_BUYABANS
                ? $this->buyabansDemandQuery()
                : $this->snapshotQuery();

            foreach ($query->cursor() as $row) {
                // §1h: `snapshot_date` is a 'date'-cast column that stores a
                // time component, so it is normalised with SQL DATE() in the
                // query rather than trusted as a bare 'Y-m-d' string here.
                $date = (string) $row->date;
                $skuId = (int) $row->sku_id;

                [$onPromotion, $discount] = $this->promotionStateFor($promotions, $skuId, $date);

                fputcsv($handle, [
                    $date,
                    (int) $row->warehouse_id,
                    $skuId,
                    $row->category_id === null ? '' : (int) $row->category_id,
                    $row->brand_id === null ? '' : (int) $row->brand_id,
                    (int) $row->sold_qty,
                    (int) $row->opening_qty,
                    (int) $row->closing_qty,
                    (int) $row->available_qty,
                    (int) $row->received_qty,
                    (int) $row->adjustment_qty,
                    (int) ($row->stockout_minutes ?? 0),
                    (int) $row->stockout_flag,
                    $onPromotion,
                    $discount,
                    (float) ($row->selling_price ?? 0),
                ]);

                $rows++;
                $series[$row->warehouse_id.'-'.$skuId] = true;

                // Min/max across the whole export, not the first and last row.
                // Rows are ordered by series then date, so the first row is one
                // series' earliest day and the last row is a different series'
                // latest day — reporting those as the dataset's range makes a
                // three-year export look like a three-week one.
                if ($firstDate === null || $date < $firstDate) {
                    $firstDate = $date;
                }

                if ($lastDate === null || $date > $lastDate) {
                    $lastDate = $date;
                }
            }

            fclose($handle);

            return [
                'success' => true,
                'message' => "Exported {$rows} rows across ".count($series)." series from the '{$source}' source to {$relativePath}",
                'data' => [
                    'path' => $absolutePath,
                    'rows' => $rows,
                    'series' => count($series),
                    'first_date' => $firstDate,
                    'last_date' => $lastDate,
                ],
            ];
        } catch (Throwable $exception) {
            Log::error('Failed exporting ML training data', [
                'exception' => $exception->getMessage(),
                'path' => $absolutePath,
            ]);

            return ['success' => false, 'message' => 'Error exporting ML training data'];
        }
    }

    /**
     * The BuyAbans demand feed, shaped into the same column contract as
     * {@see snapshotQuery()}.
     *
     * **What is real here and what is not.** `sold_qty` is genuine, measured
     * demand — the target, and the reason to prefer this source. The
     * stock-derived covariates are not: the back office reports a *current*
     * stock position, not a per-day balance history, so `opening_qty`,
     * `closing_qty` and `available_qty` all carry that one present-day figure
     * for every day of the series, and `received_qty`, `adjustment_qty` and
     * `stockout_minutes` are simply unknown and export as zero.
     *
     * This is the same treatment, and the same admission, that price already
     * gets in this class: a static per-SKU feature standing in for a daily
     * series the schema does not hold. It is recorded rather than hidden
     * because a neural model reads those columns as fact — a model trained on
     * this source is learning calendar, price, promotion, category and brand
     * effects on real demand, and is *not* learning anything about stock
     * availability. The statistical baselines are unaffected: they read the
     * daily series and nothing else.
     *
     * `warehouse_id` is the series' location key. At the warehouse grain it is
     * the real warehouse id; at the channel and national grains no warehouse
     * exists, so it is a dense rank over `location_code` — stable within an
     * export, and never mixed, because an export covers one grain.
     */
    private function buyabansDemandQuery(): Builder
    {
        $grain = (string) config('services.buyabans.grain', 'warehouse');

        // Dense-ranked here rather than in SQL so the mapping is explicit and
        // the same on every database engine.
        $locationKey = 'COALESCE(d.warehouse_id, 0)';

        if ($grain !== 'warehouse') {
            $codes = DB::table('buyabans_daily_demands')
                ->where('grain', $grain)
                ->distinct()
                ->orderBy('location_code')
                ->pluck('location_code');

            $cases = [];

            foreach ($codes as $index => $code) {
                $cases[] = 'WHEN '.DB::connection()->getPdo()->quote((string) $code).' THEN '.($index + 1);
            }

            $locationKey = $cases === []
                ? '0'
                : 'CASE d.location_code '.implode(' ', $cases).' ELSE 0 END';
        }

        return DB::table('buyabans_daily_demands as d')
            ->join('skus', 'skus.id', '=', 'd.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->leftJoin('buyabans_stock_levels as bsl', function ($join) {
                $join->on('bsl.sku_id', '=', 'd.sku_id')->where('bsl.inventory_source_code', '=', 'default');
            })
            ->where('d.grain', $grain)
            // At the warehouse grain, drop demand that carries no location.
            // Some orders genuinely have no showroom code, so their demand is
            // real but unattributed — and ForecastRunService::pairsInScope()
            // will never forecast such a pair. Exporting it anyway would train
            // on a series that is never served, which is exactly the
            // train/serve mismatch this source is supposed to avoid.
            ->when(
                $grain === 'warehouse',
                fn ($query) => $query->whereNotNull('d.warehouse_id')
            )
            ->select([
                DB::raw('DATE(d.demand_date) as date'),
                DB::raw($locationKey.' as warehouse_id'),
                'd.sku_id',
                'products.category_id',
                'products.brand_id',
                'd.sold_qty',
                DB::raw('COALESCE(bsl.qty, 0) as opening_qty'),
                DB::raw('COALESCE(bsl.qty, 0) as closing_qty'),
                DB::raw('COALESCE(bsl.qty, 0) as available_qty'),
                DB::raw('0 as received_qty'),
                DB::raw('0 as adjustment_qty'),
                DB::raw('0 as stockout_minutes'),
                DB::raw('0 as stockout_flag'),
                // The price actually charged on the day, which this source does
                // have — unlike the ledger source, where only today's price
                // exists. Falls back to the SKU's current price on a day the
                // aggregate reported none.
                DB::raw('CASE WHEN d.avg_price > 0 THEN d.avg_price ELSE skus.selling_price END as selling_price'),
            ])
            ->orderByRaw($locationKey)
            ->orderBy('d.sku_id')
            ->orderBy('d.demand_date');
    }

    /**
     * One row per warehouse/SKU/day, ordered so each series is contiguous
     * and chronological — the training pipeline relies on that ordering
     * instead of re-sorting hundreds of thousands of rows in pandas.
     */
    private function snapshotQuery(): Builder
    {
        return DB::table('inventory_daily_snapshots as s')
            ->join('skus', 'skus.id', '=', 's.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->select([
                DB::raw('DATE(s.snapshot_date) as date'),
                's.warehouse_id',
                's.sku_id',
                'products.category_id',
                'products.brand_id',
                's.sold_qty',
                's.opening_qty',
                's.closing_qty',
                's.available_qty',
                's.received_qty',
                's.adjustment_qty',
                's.stockout_minutes',
                's.stockout_flag',
                'skus.selling_price',
            ])
            ->orderBy('s.warehouse_id')
            ->orderBy('s.sku_id')
            ->orderBy('s.snapshot_date');
    }

    /**
     * Promotion windows keyed by SKU. Loaded once into memory rather than
     * joined per row: a date-range join against `promotion_skus` fans out
     * whenever two promotions overlap on the same SKU, which would silently
     * duplicate snapshot rows and corrupt the target series.
     *
     * @return array<int, list<array{start: string, end: string, discount: float}>>
     */
    private function promotionWindows(): array
    {
        $windows = [];

        $rows = DB::table('promotion_skus as ps')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->whereNull('p.deleted_at')
            ->select([
                'ps.sku_id',
                DB::raw('DATE(p.start_date) as start_date'),
                DB::raw('DATE(p.end_date) as end_date'),
                'p.discount_type',
                'p.discount_value',
            ])
            ->get();

        foreach ($rows as $row) {
            $windows[(int) $row->sku_id][] = [
                'start' => (string) $row->start_date,
                'end' => (string) $row->end_date,
                // A fixed-amount discount has no meaningful percentage
                // without the price it applied to, and mixing the two units
                // in one column would teach the model nonsense. Percentage
                // promotions carry their real value; fixed ones contribute
                // the on/off flag only.
                //
                // Compared against the enum's own backing value, not a
                // string literal: these are stored uppercase ('PERCENTAGE'),
                // and a lowercase literal here silently exported every
                // discount as 0.0 rather than failing loudly.
                'discount' => $row->discount_type === PromotionDiscountType::Percentage->value
                    ? (float) $row->discount_value
                    : 0.0,
            ];
        }

        return $windows;
    }

    /**
     * @param  array<int, list<array{start: string, end: string, discount: float}>>  $windows
     * @return array{0: int, 1: float}
     */
    private function promotionStateFor(array $windows, int $skuId, string $date): array
    {
        foreach ($windows[$skuId] ?? [] as $window) {
            if ($date >= $window['start'] && $date <= $window['end']) {
                return [1, $window['discount']];
            }
        }

        return [0, 0.0];
    }
}
