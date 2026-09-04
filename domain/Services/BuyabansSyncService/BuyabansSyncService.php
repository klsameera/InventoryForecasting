<?php

declare(strict_types=1);

namespace Domain\Services\BuyabansSyncService;

use App\Models\Brand;
use App\Models\BuyabansDailyDemand;
use App\Models\BuyabansStockLevel;
use App\Models\BuyabansSyncRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Domain\Services\BuyabansClient\BuyabansClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls the catalog, locations, stock and demand history this application
 * forecasts on out of the BuyAbans back office.
 *
 * **The premise.** This is a prediction system, not a stock manager. It authors
 * no catalog and records no stock movements of its own; the back office is the
 * system of record for all of it. Everything here is a one-way read.
 *
 * **Idempotence is the whole design.** A sync is expected to run nightly, to be
 * re-run by hand after a failure, and to overlap windows it has already
 * covered. So every write is an upsert keyed on something stable and
 * externally meaningful:
 *
 * | Local record | Keyed on |
 * | --- | --- |
 * | Category | `code` = `bab-c{buyabans id}` |
 * | Brand | `code` = `bab-b{option id}` |
 * | Warehouse | `code` = the real `location_code` |
 * | Sku / Product | `skus.sku` = the back office SKU code |
 * | Daily demand | grain + location + SKU + date |
 * | Stock level | SKU + inventory source |
 *
 * Prefixed synthetic codes rather than raw ids because `code` is user-visible
 * and unique: a bare `92` tells nobody anything, and would collide the first
 * time somebody created a category by hand.
 *
 * **What it deliberately does not do.** It never writes to `inventories`,
 * `stock_movements` or any ledger table. Those are produced by this
 * application's own stock modules and their balances are derived from
 * movements; injecting a synced figure would break that invariant silently.
 * Synced stock lands in {@see BuyabansStockLevel} instead, plainly labelled.
 */
final class BuyabansSyncService
{
    /** Prefix marking a local record as sourced from the back office. */
    public const CODE_PREFIX = 'bab-';

    /** Rows per upsert batch. */
    private const CHUNK = 500;

    /**
     * Local placeholder used when a synced product's category cannot be
     * resolved. `products.category_id` is NOT NULL, so a product with no usable
     * category would otherwise be dropped — and a product that sells is worth
     * forecasting whether or not its categorisation came across cleanly.
     */
    private const UNCATEGORISED_CODE = self::CODE_PREFIX.'uncategorised';

    public function __construct(private BuyabansClient $client) {}

    /**
     * Run every stage in dependency order: locations and catalog first, because
     * demand rows resolve against them.
     *
     * Stages are independent runs rather than one transaction — a demand sync
     * that fails halfway should not roll back a catalog that imported fine, and
     * the next run picks up where this one stopped.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncAll(array $options = []): array
    {
        $results = [];

        foreach (['locations', 'categories', 'brands', 'products', 'stock', 'demand'] as $stage) {
            $method = 'sync'.Str::studly($stage);
            $results[$stage] = $this->{$method}($options);

            if (! $results[$stage]['success']) {
                return [
                    'success' => false,
                    'message' => "Sync stopped at '{$stage}': ".$results[$stage]['message'],
                    'data' => $results,
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'BuyAbans sync completed successfully',
            'data' => $results,
        ];
    }

    /**
     * Headline counts from the back office, without syncing anything. The UI
     * shows this so an operator can see how much history exists before
     * committing to a pull.
     *
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function probe(): array
    {
        try {
            return [
                'success' => true,
                'message' => 'Connected to the BuyAbans back office',
                'data' => $this->client->get('/api/forecasting/meta'),
            ];
        } catch (Throwable $exception) {
            Log::error('BuyAbans probe failed', ['exception' => $exception->getMessage()]);

            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * Sync history, most recent first — what the index page lists.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, BuyabansSyncRun>
     */
    public function runs(array $filters = []): LengthAwarePaginator
    {
        return BuyabansSyncRun::query()
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($inner) use ($filters) {
                    // Failure messages are the reason anyone searches this
                    // listing, so they are searchable alongside the stage name.
                    $inner->where('stage', 'like', '%'.$filters['search'].'%')
                        ->orWhere('message', 'like', '%'.$filters['search'].'%');
                })
            )
            ->when(
                filled($filters['stage'] ?? null),
                fn ($query) => $query->where('stage', $filters['stage'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->latest('started_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * What the synced demand data actually amounts to — the numbers that decide
     * whether it is worth training on. Reported per grain, because rows of
     * different grains describe the same sales and must never be added up
     * together.
     *
     * @return array<string, mixed>
     */
    public function demandSummary(): array
    {
        $byGrain = BuyabansDailyDemand::query()
            ->selectRaw('grain, COUNT(*) AS rows_count, COUNT(DISTINCT sku_code) AS skus, COUNT(DISTINCT location_code) AS locations')
            ->selectRaw('MIN(demand_date) AS first_date, MAX(demand_date) AS last_date, SUM(sold_qty) AS units')
            ->selectRaw('COUNT(DISTINCT CASE WHEN sku_id IS NULL THEN sku_code END) AS unmatched_skus')
            ->groupBy('grain')
            ->get()
            ->map(fn ($row) => [
                'grain' => (string) $row->grain,
                'rows' => (int) $row->rows_count,
                'skus' => (int) $row->skus,
                'locations' => (int) $row->locations,
                'first_date' => $row->first_date,
                'last_date' => $row->last_date,
                'units' => (float) $row->units,
                'unmatched_skus' => (int) $row->unmatched_skus,
            ])
            ->all();

        return [
            'by_grain' => $byGrain,
            'catalog' => [
                'categories' => Category::query()->where('code', 'like', self::CODE_PREFIX.'%')->count(),
                'brands' => Brand::query()->where('code', 'like', self::CODE_PREFIX.'%')->count(),
                'skus' => Sku::query()->count(),
                'warehouses' => Warehouse::query()->count(),
                'stock_levels' => BuyabansStockLevel::query()->count(),
            ],
            'configured' => $this->client->isConfigured(),
            'endpoint' => (string) config('services.buyabans.url'),
        ];
    }

    /**
     * Warehouses. Only the warehouse grain is materialised as local
     * {@see Warehouse} records — channels and inventory sources are location
     * dimensions of the demand feed, not places this application would ever
     * hold stock, so they stay as `location_code` strings on the demand rows.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncLocations(array $options = []): array
    {
        return $this->run('locations', $options, function (BuyabansSyncRun $run): array {
            $payload = $this->client->get('/api/forecasting/locations');
            $warehouses = $payload['warehouses'] ?? [];
            $written = 0;

            foreach ($warehouses as $warehouse) {
                $code = trim((string) ($warehouse['location_code'] ?? ''));

                if ($code === '') {
                    continue;
                }

                Warehouse::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => (string) ($warehouse['name'] ?? $code),
                        'address' => $warehouse['address'] ?? null,
                        'status' => true,
                    ]
                );

                $written++;
            }

            $run->summary = [
                'warehouses' => count($warehouses),
                'channels' => count($payload['channels'] ?? []),
                'inventory_sources' => count($payload['inventory_sources'] ?? []),
            ];

            return ['pages' => 1, 'fetched' => count($warehouses), 'written' => $written];
        });
    }

    /**
     * Categories. Parents are linked in a second pass, because a child can
     * arrive before its parent and the first pass has no id to point at yet.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncCategories(array $options = []): array
    {
        return $this->run('categories', $options, function (BuyabansSyncRun $run) use ($options): array {
            $parents = [];

            $result = $this->client->walk(
                '/api/forecasting/categories',
                ['limit' => $this->pageSize($options)],
                function (array $items) use (&$parents): int {
                    $written = 0;

                    foreach ($items as $item) {
                        $externalId = (int) ($item['id'] ?? 0);

                        if ($externalId === 0) {
                            continue;
                        }

                        $name = trim((string) ($item['name'] ?? ''));

                        Category::updateOrCreate(
                            ['code' => $this->categoryCode($externalId)],
                            [
                                'name' => $name !== '' ? $name : "Category {$externalId}",
                                'status' => (bool) ($item['status'] ?? true),
                            ]
                        );

                        if (! empty($item['parent_id'])) {
                            $parents[$externalId] = (int) $item['parent_id'];
                        }

                        $written++;
                    }

                    return $written;
                }
            );

            $linked = $this->linkCategoryParents($parents);

            $run->summary = ['parents_linked' => $linked];

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncBrands(array $options = []): array
    {
        return $this->run('brands', $options, function (BuyabansSyncRun $run): array {
            $payload = $this->client->get('/api/forecasting/brands');
            $items = $payload['items'] ?? [];
            $written = 0;

            foreach ($items as $item) {
                $externalId = (int) ($item['id'] ?? 0);
                $name = trim((string) ($item['name'] ?? $item['admin_name'] ?? ''));

                if ($externalId === 0 || $name === '') {
                    continue;
                }

                Brand::updateOrCreate(
                    ['code' => self::CODE_PREFIX.'b'.$externalId],
                    ['name' => $name, 'status' => true]
                );

                $written++;
            }

            return ['pages' => 1, 'fetched' => count($items), 'written' => $written];
        });
    }

    /**
     * Products and their SKUs.
     *
     * A back-office simple product maps to one local {@see Product} plus one
     * {@see Sku}. The SKU code is the join key in both directions — it is what
     * the demand feed reports against, and the only stable identifier the two
     * systems genuinely share.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncProducts(array $options = []): array
    {
        return $this->run('products', $options, function (BuyabansSyncRun $run) use ($options): array {
            $categories = $this->categoryMap();
            $brands = $this->brandMap();
            $fallbackCategoryId = $this->uncategorisedCategoryId();
            $uncategorised = 0;

            $result = $this->client->walk(
                '/api/forecasting/products',
                ['limit' => $this->pageSize($options), 'sellable_only' => 1],
                function (array $items) use ($categories, $brands, $fallbackCategoryId, &$uncategorised): int {
                    $written = 0;

                    foreach ($items as $item) {
                        $skuCode = trim((string) ($item['sku'] ?? ''));

                        if ($skuCode === '') {
                            continue;
                        }

                        $categoryId = $this->resolveCategoryId($item, $categories);

                        if ($categoryId === null) {
                            $categoryId = $fallbackCategoryId;
                            $uncategorised++;
                        }

                        $brandId = $this->resolveBrandId($item, $brands);
                        $price = (float) ($item['price'] ?? 0);
                        $name = trim((string) ($item['name'] ?? $skuCode));

                        DB::transaction(function () use ($skuCode, $name, $categoryId, $brandId, $price, $item) {
                            $sku = Sku::withTrashed()->where('sku', $skuCode)->first();

                            $product = $sku?->product ?? new Product;

                            $product->fill([
                                'category_id' => $categoryId,
                                'brand_id' => $brandId,
                                'name' => $name,
                                'product_type' => 'simple',
                                'status' => (bool) ($item['status'] ?? true),
                            ]);
                            $product->save();

                            $sku ??= new Sku;
                            $sku->fill([
                                'product_id' => $product->id,
                                'sku' => $skuCode,
                                'selling_price' => $price,
                                'status' => (bool) ($item['status'] ?? true),
                            ]);

                            // cost_price is genuinely unknown here: the back
                            // office exposes selling prices, not landed cost.
                            // Leaving it at its default is honest; guessing a
                            // margin would put invented money into every margin
                            // report downstream.
                            $sku->save();
                        });

                        $written++;
                    }

                    return $written;
                }
            );

            $run->summary = ['uncategorised' => $uncategorised];

            return $result;
        });
    }

    /**
     * Current stock on hand per SKU per inventory source.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncStock(array $options = []): array
    {
        return $this->run('stock', $options, function (BuyabansSyncRun $run) use ($options): array {
            $skus = $this->skuMap();
            $now = now();
            $matched = 0;

            $result = $this->client->walk(
                '/api/forecasting/inventory',
                ['limit' => $this->pageSize($options)],
                function (array $items) use ($skus, $now, &$matched): int {
                    $rows = [];

                    foreach ($items as $item) {
                        $skuCode = trim((string) ($item['sku'] ?? ''));
                        $source = trim((string) ($item['inventory_source_code'] ?? ''));

                        if ($skuCode === '' || $source === '') {
                            continue;
                        }

                        $skuId = $skus[$skuCode] ?? null;

                        if ($skuId !== null) {
                            $matched++;
                        }

                        $rows[] = [
                            'sku_code' => $skuCode,
                            'sku_id' => $skuId,
                            'inventory_source_code' => $source,
                            'qty' => (int) ($item['qty'] ?? 0),
                            'synced_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    return $this->upsert(
                        BuyabansStockLevel::class,
                        $rows,
                        ['sku_code', 'inventory_source_code'],
                        ['sku_id', 'qty', 'synced_at', 'updated_at']
                    );
                }
            );

            $run->summary = ['matched_local_skus' => $matched];

            return $result;
        });
    }

    /**
     * The demand history — the reason the rest of this exists.
     *
     * Pulls one row per (date, SKU, location) at the configured grain and
     * upserts it, so re-running over an overlapping window corrects rows rather
     * than double-counting them.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function syncDemand(array $options = []): array
    {
        return $this->run('demand', $options, function (BuyabansSyncRun $run) use ($options): array {
            $grain = $this->grain($options);
            [$from, $to] = $this->window($options);

            $run->grain = $grain;
            $run->from_date = $from;
            $run->to_date = $to;

            $skus = $this->skuMap();
            $warehouses = $this->warehouseMap();
            $unmatchedSkus = [];

            $onPage = function (array $items) use ($grain, $skus, $warehouses, &$unmatchedSkus): int {
                $now = now();
                $rows = [];

                foreach ($items as $item) {
                    $skuCode = trim((string) ($item['sku'] ?? ''));

                    if ($skuCode === '') {
                        continue;
                    }

                    $locationCode = $item['location_code'] ?? null;
                    $locationCode = $locationCode === null ? null : (string) $locationCode;

                    $skuId = $skus[$skuCode] ?? null;

                    if ($skuId === null) {
                        $unmatchedSkus[$skuCode] = true;
                    }

                    $rows[] = [
                        'demand_date' => (string) $item['date'],
                        'grain' => $grain,
                        // The unique index spans location_code, and MySQL
                        // treats NULLs as distinct there — which would let
                        // the national grain insert a duplicate row per
                        // sync instead of upserting. An empty string is a
                        // real value and keys correctly.
                        'location_code' => $locationCode ?? '',
                        'sku_code' => $skuCode,
                        'sku_id' => $skuId,
                        'warehouse_id' => $grain === BuyabansDailyDemand::GRAIN_WAREHOUSE
                            ? ($warehouses[$locationCode] ?? null)
                            : null,
                        'sold_qty' => (float) ($item['sold_qty'] ?? 0),
                        'revenue' => (float) ($item['revenue'] ?? 0),
                        'avg_price' => (float) ($item['avg_price'] ?? 0),
                        'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                        'order_count' => (int) ($item['order_count'] ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                return $this->upsert(
                    BuyabansDailyDemand::class,
                    $rows,
                    ['grain', 'location_code', 'sku_code', 'demand_date'],
                    ['sku_id', 'warehouse_id', 'sold_qty', 'revenue', 'avg_price', 'discount_amount', 'order_count', 'updated_at']
                );
            };

            // Walk the range one month at a time rather than paging by offset
            // across the whole window.
            //
            // `sales-daily` is an aggregate, so it can only page by offset —
            // and MySQL answers a large OFFSET by generating and discarding
            // every preceding row. Over three years that degrades quadratically:
            // measured at ~13k rows a minute and getting slower, a full history
            // sync would have taken the better part of an hour. Bounding each
            // request to one month keeps every offset small, so the cost stays
            // linear in the data actually returned.
            $result = ['pages' => 0, 'fetched' => 0, 'written' => 0];
            $windowStart = $from;

            while ($windowStart->lte($to)) {
                $windowEnd = $windowStart->addMonth()->subDay();

                if ($windowEnd->gt($to)) {
                    $windowEnd = $to;
                }

                $slice = $this->client->walkOffset(
                    '/api/forecasting/sales-daily',
                    [
                        'grain' => $grain,
                        'limit' => $this->pageSize($options),
                        'from' => $windowStart->toDateString(),
                        'to' => $windowEnd->toDateString(),
                    ],
                    $onPage
                );

                $result['pages'] += $slice['pages'];
                $result['fetched'] += $slice['fetched'];
                $result['written'] += $slice['written'];

                $windowStart = $windowEnd->addDay();
            }

            $run->summary = [
                'grain' => $grain,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'unmatched_skus' => count($unmatchedSkus),
            ];

            return $result;
        });
    }

    /**
     * Wraps a stage: opens a run row, times it, records the outcome, and
     * converts any throwable into a recorded failure rather than an exception
     * escaping into a scheduled command.
     *
     * @param  array<string, mixed>  $options
     * @param  callable(BuyabansSyncRun): array{pages: int, fetched: int, written: int}  $work
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    private function run(string $stage, array $options, callable $work): array
    {
        $this->closeAbandonedRuns($stage);

        $run = BuyabansSyncRun::create([
            'stage' => $stage,
            'status' => BuyabansSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $result = $work($run);

            $run->fill([
                'status' => BuyabansSyncRun::STATUS_SUCCESS,
                'pages' => $result['pages'],
                'records_fetched' => $result['fetched'],
                'records_written' => $result['written'],
                'finished_at' => now(),
            ])->save();

            return [
                'success' => true,
                'message' => "Synced {$result['written']} {$stage} records",
                'data' => [
                    'run_id' => $run->id,
                    'stage' => $stage,
                    'fetched' => $result['fetched'],
                    'written' => $result['written'],
                    'pages' => $result['pages'],
                    'summary' => $run->summary,
                ],
            ];
        } catch (Throwable $exception) {
            $run->fill([
                'status' => BuyabansSyncRun::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();

            Log::error("BuyAbans {$stage} sync failed", [
                'exception' => $exception->getMessage(),
                'run_id' => $run->id,
            ]);

            return ['success' => false, 'message' => "Failed syncing {$stage}: ".$exception->getMessage()];
        }
    }

    /**
     * Closes out runs of this stage left in `running`.
     *
     * A run row is opened by the process that does the work and closed by that
     * same process — so a run killed mid-flight (a crash, a timeout, a stopped
     * container) can never close its own row and sits in `running` forever,
     * making the listing steadily less honest. Nothing else can be running this
     * stage anyway: the schedule uses `withoutOverlapping()`, and starting a new
     * one is proof the previous attempt is over.
     */
    private function closeAbandonedRuns(string $stage): void
    {
        BuyabansSyncRun::query()
            ->where('stage', $stage)
            ->where('status', BuyabansSyncRun::STATUS_RUNNING)
            ->update([
                'status' => BuyabansSyncRun::STATUS_FAILED,
                'message' => 'Abandoned — the process ended before this run finished. Superseded by a later run.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    private function upsert(string $model, array $rows, array $uniqueBy, array $update): int
    {
        if ($rows === []) {
            return 0;
        }

        $written = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $model::query()->upsert($chunk, $uniqueBy, $update);
            $written += count($chunk);
        }

        return $written;
    }

    /**
     * Second pass over categories: now that every category exists locally, the
     * parent links can be resolved to local ids.
     *
     * @param  array<int, int>  $parents  external child id => external parent id
     */
    private function linkCategoryParents(array $parents): int
    {
        if ($parents === []) {
            return 0;
        }

        $map = $this->categoryMap();
        $linked = 0;

        foreach ($parents as $childExternalId => $parentExternalId) {
            $childId = $map[$childExternalId] ?? null;
            $parentId = $map[$parentExternalId] ?? null;

            // A parent outside the synced set (or the category's own id, which
            // some catalogs use as a root marker) is skipped rather than
            // written — a self-parent would make the tree cyclic.
            if ($childId === null || $parentId === null || $childId === $parentId) {
                continue;
            }

            Category::where('id', $childId)->update(['parent_id' => $parentId]);
            $linked++;
        }

        return $linked;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, int>  $categories
     */
    private function resolveCategoryId(array $item, array $categories): ?int
    {
        foreach ($item['categories'] ?? [] as $category) {
            $localId = $categories[(int) ($category['id'] ?? 0)] ?? null;

            if ($localId !== null) {
                // A back-office product can sit in several categories; this
                // schema allows one, so the first resolvable one wins.
                return $localId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $brands
     */
    private function resolveBrandId(array $item, array $brands): ?int
    {
        $name = trim((string) ($item['brand_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return $brands[mb_strtolower($name)] ?? null;
    }

    /**
     * @return array<int, int> external category id => local id
     */
    private function categoryMap(): array
    {
        $map = [];

        Category::query()
            ->where('code', 'like', self::CODE_PREFIX.'c%')
            ->select(['id', 'code'])
            ->chunkById(1000, function ($categories) use (&$map) {
                foreach ($categories as $category) {
                    $externalId = (int) Str::after($category->code, self::CODE_PREFIX.'c');

                    if ($externalId > 0) {
                        $map[$externalId] = (int) $category->id;
                    }
                }
            });

        return $map;
    }

    /**
     * Keyed by lowercased name, because the product feed reports a brand by
     * label rather than by option id.
     *
     * @return array<string, int>
     */
    private function brandMap(): array
    {
        $map = [];

        Brand::query()->select(['id', 'name'])->chunkById(1000, function ($brands) use (&$map) {
            foreach ($brands as $brand) {
                $map[mb_strtolower((string) $brand->name)] = (int) $brand->id;
            }
        });

        return $map;
    }

    /**
     * @return array<string, int> sku code => local sku id
     */
    private function skuMap(): array
    {
        $map = [];

        Sku::query()->select(['id', 'sku'])->chunkById(2000, function ($skus) use (&$map) {
            foreach ($skus as $sku) {
                $map[(string) $sku->sku] = (int) $sku->id;
            }
        });

        return $map;
    }

    /**
     * @return array<string, int> warehouse code => local warehouse id
     */
    private function warehouseMap(): array
    {
        return Warehouse::query()
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function uncategorisedCategoryId(): int
    {
        return (int) Category::firstOrCreate(
            ['code' => self::UNCATEGORISED_CODE],
            ['name' => 'Uncategorised (BuyAbans)', 'status' => true]
        )->id;
    }

    private function categoryCode(int $externalId): string
    {
        return self::CODE_PREFIX.'c'.$externalId;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function pageSize(array $options): int
    {
        return (int) ($options['page_size'] ?? config('services.buyabans.page_size', 1000));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function grain(array $options): string
    {
        $grain = (string) ($options['grain'] ?? config('services.buyabans.grain', 'warehouse'));

        return in_array($grain, BuyabansDailyDemand::GRAINS, true)
            ? $grain
            : BuyabansDailyDemand::GRAIN_WAREHOUSE;
    }

    /**
     * The date window to pull demand for. Defaults to the full configured
     * history; `days` narrows it for an incremental nightly run.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $options): array
    {
        $to = isset($options['to'])
            ? CarbonImmutable::parse((string) $options['to'])
            : CarbonImmutable::today();

        if (isset($options['from'])) {
            return [CarbonImmutable::parse((string) $options['from']), $to];
        }

        $days = (int) ($options['days'] ?? config('services.buyabans.history_days', 1100));

        return [$to->subDays(max(1, $days)), $to];
    }
}
