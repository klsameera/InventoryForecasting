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
     * @return array{success: bool, message: string, data?: array{path: string, rows: int, series: int, first_date: ?string, last_date: ?string}}
     */
    public function export(?string $relativePath = null): array
    {
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

            foreach ($this->snapshotQuery()->cursor() as $row) {
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
                $firstDate ??= $date;
                $lastDate = $date;
            }

            fclose($handle);

            return [
                'success' => true,
                'message' => "Exported {$rows} rows across ".count($series)." series to {$relativePath}",
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
