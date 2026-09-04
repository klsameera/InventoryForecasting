<?php

declare(strict_types=1);

namespace Domain\Services\DashboardService;

use App\Models\InventoryRecommendation;
use Carbon\CarbonImmutable;
use Domain\Services\InventoryAnalyticsService\InventoryAnalyticsService;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's figures, computed on request from current data.
 *
 * Never persisted and never cached — the same "computed report, not a CRUD
 * module" shape as {@see InventoryAnalyticsService}. This Service owns no table
 * and takes no model in its constructor.
 *
 * **Every tile here is either a real number or absent.** The page renders "—"
 * for a metric it is not given, and that is the correct output for a figure
 * this system cannot yet honestly produce — see {@see forecastAccuracy()}. A
 * dashboard that invents a plausible number is worse than one that admits it
 * has none, because nobody checks a number that looks fine.
 *
 * **Portability is a constraint, not a preference.** Production is MySQL and
 * the test suite runs on in-memory SQLite, so nothing here may use a
 * MySQL-only date function on a path a test reaches. Where a single grouped
 * query would have needed `DATEDIFF`, this class loops instead and says so.
 */
final class DashboardService
{
    /** Weeks of history behind the trend chart and every sparkline. */
    private const TREND_WEEKS = 12;

    /** How many SKUs the top-movers chart ranks. */
    private const TOP_MOVERS = 8;

    /** How many categories and locations their respective charts rank. */
    private const TOP_CATEGORIES = 8;

    private const TOP_LOCATIONS = 8;

    /** Window the headline demand, revenue and cover figures are measured over. */
    private const WINDOW_DAYS = 30;

    /**
     * Days-of-cover bands, in ascending order, with the chart slot each is
     * drawn in.
     *
     * The slot is fixed per band and never assigned by rank or by size, so a
     * band that empties out does not repaint the others. Slot 6 (red) is out of
     * stock, 2 (amber) is running out, 4 (green) is healthy, and the two
     * long-cover bands take 3 and 5 — capital sitting still is not an error, so
     * it is not drawn as one.
     */
    private const COVER_BANDS = [
        ['label' => 'Out of stock', 'upToDays' => 0.0, 'slot' => 5],
        ['label' => 'Under 2 weeks', 'upToDays' => 14.0, 'slot' => 1],
        ['label' => '2 weeks – 2 months', 'upToDays' => 60.0, 'slot' => 3],
        ['label' => '2 – 6 months', 'upToDays' => 180.0, 'slot' => 2],
        ['label' => 'Over 6 months', 'upToDays' => null, 'slot' => 4],
    ];

    /*
     * Every date window below is a plain range against a bare column, closed
     * with endOfDay(). Neither whereDate() nor a naive string range is correct
     * here — they are wrong in opposite directions. See endOfDay().
     */

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $lastDay = $this->lastDemandDate();
        $weeks = $this->weeklyTotals($lastDay);
        $window = $this->windowTotals($lastDay);

        // Computed once and used twice: the out-of-stock tile is the first band
        // of the same distribution the cover chart draws.
        $cover = $this->coverDistribution($lastDay);

        return [
            'currency' => (string) config('services.buyabans.currency', 'LKR'),
            'window' => $this->windowLabel($lastDay),
            'metrics' => [
                'revenue' => $this->measure($window['revenue'] ?? null, $weeks, 'revenue'),
                'unitsSold' => $this->measure($window['units'] ?? null, $weeks, 'units'),
                'orders' => $this->measure($window['orders'] ?? null, $weeks, 'orders'),
                'averageOrderValue' => $this->averageOrderValue($window, $weeks),
                'stockValue' => $this->stockValue(),
                'stockCoverage' => $this->stockCoverage($lastDay),
                'outOfStockLines' => $this->outOfStockLines($cover),
                'reorderAlerts' => $this->reorderAlerts(),
                'skusTracked' => $this->skusTracked($lastDay),
                'forecastAccuracy' => $this->forecastAccuracy(),
            ],
            'demandTrend' => $this->demandTrend($weeks),
            'revenueTrend' => $this->revenueTrend($weeks),
            'topMovers' => $this->topMovers($lastDay),
            'revenueByCategory' => $this->revenueByCategory($lastDay),
            'coverHealth' => $this->coverHealth($cover),
            'demandByLocation' => $this->demandByLocation($lastDay),
            'forecastStatus' => $this->forecastStatus(),
        ];
    }

    /**
     * The window every headline figure describes, for the page to say out loud.
     *
     * Anchored to the last synced day rather than to today, for the reason
     * given on {@see lastDemandDate()}. Without this the page would show
     * thirty-day figures beside a chart that stops a fortnight earlier and
     * leave the reader to work out why.
     *
     * @return array{from: string, to: string, days: int}|null
     */
    private function windowLabel(?CarbonImmutable $lastDay): ?array
    {
        if ($lastDay === null) {
            return null;
        }

        return [
            'from' => $lastDay->subDays(self::WINDOW_DAYS - 1)->format('d M Y'),
            'to' => $lastDay->format('d M Y'),
            'days' => self::WINDOW_DAYS,
        ];
    }

    /**
     * Units, revenue and orders over the headline window.
     *
     * @return array{units: float, revenue: float, orders: float}|null
     */
    private function windowTotals(?CarbonImmutable $lastDay): ?array
    {
        if ($lastDay === null) {
            return null;
        }

        $row = $this->demandQuery()
            ->where('demand_date', '>=', $lastDay->subDays(self::WINDOW_DAYS - 1)->toDateString())
            ->where('demand_date', '<=', $this->endOfDay($lastDay->toDateString()))
            ->selectRaw('SUM(sold_qty) AS units, SUM(revenue) AS revenue, SUM(order_count) AS orders')
            ->first();

        if ($row === null || $row->units === null) {
            return null;
        }

        return [
            'units' => round((float) $row->units, 2),
            'revenue' => round((float) $row->revenue, 2),
            'orders' => round((float) $row->orders, 2),
        ];
    }

    /**
     * Weekly totals across the trend window, oldest week first.
     *
     * One query per week returning all three measures together — the trend
     * chart, the revenue chart, three tiles and all of their deltas read from
     * this one array.
     *
     * A single `GROUP BY FLOOR(DATEDIFF(?, demand_date) / 7)` would collapse
     * the loop into one query, and is what production would want at scale. It
     * is not used because `DATEDIFF` does not exist in SQLite, which is what
     * the suite runs on — and a dashboard query first exercised in production
     * is one nobody notices breaking.
     *
     * @return list<array{label: string, units: float, revenue: float, orders: float}>
     */
    private function weeklyTotals(?CarbonImmutable $lastDay): array
    {
        if ($lastDay === null) {
            return [];
        }

        $weeks = [];

        for ($week = self::TREND_WEEKS - 1; $week >= 0; $week--) {
            $end = $lastDay->subWeeks($week);
            $start = $end->subDays(6);

            $row = $this->demandQuery()
                ->where('demand_date', '>=', $start->toDateString())
                ->where('demand_date', '<=', $this->endOfDay($end->toDateString()))
                ->selectRaw('SUM(sold_qty) AS units, SUM(revenue) AS revenue, SUM(order_count) AS orders')
                ->first();

            $weeks[] = [
                'label' => $start->format('d M'),
                'units' => round((float) ($row->units ?? 0), 2),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'orders' => round((float) ($row->orders ?? 0), 2),
            ];
        }

        return $weeks;
    }

    /**
     * A tile: the window's figure, its trailing weekly shape, and the change
     * between the last four weeks and the four before them.
     *
     * @param  list<array{label: string, units: float, revenue: float, orders: float}>  $weeks
     * @return array{value: float, delta: float|null, trend: list<float>}|null
     */
    private function measure(?float $value, array $weeks, string $key): ?array
    {
        if ($value === null || $weeks === []) {
            return null;
        }

        /** @var list<float> $trend */
        $trend = array_column($weeks, $key);

        return [
            'value' => $value,
            'delta' => $this->percentChange(
                array_slice($trend, -4),
                array_slice($trend, -8, 4),
            ),
            'trend' => $trend,
        ];
    }

    /**
     * Revenue per order over the window.
     *
     * Its own tile rather than something the reader divides in their head:
     * revenue and orders can move in opposite directions, and which one is
     * driving a change is the first question anyone asks.
     *
     * @param  array{units: float, revenue: float, orders: float}|null  $window
     * @param  list<array{label: string, units: float, revenue: float, orders: float}>  $weeks
     * @return array{value: float, delta: float|null, trend: list<float>}|null
     */
    private function averageOrderValue(?array $window, array $weeks): ?array
    {
        if ($window === null || $window['orders'] <= 0.0) {
            return null;
        }

        $trend = array_map(
            fn (array $week): float => $week['orders'] > 0.0
                ? round($week['revenue'] / $week['orders'], 2)
                : 0.0,
            $weeks,
        );

        return [
            'value' => round($window['revenue'] / $window['orders'], 2),
            'delta' => $this->percentChange(
                array_slice($trend, -4),
                array_slice($trend, -8, 4),
            ),
            'trend' => array_values($trend),
        ];
    }

    /**
     * What the stock on hand would be worth at its selling price.
     *
     * **At selling price, not at cost, and the label on the page says so.**
     * `skus.cost_price` is populated for 149 of 10,892 SKUs, so a cost-based
     * valuation would silently describe 1.4% of the catalog. Retail value is
     * the figure this data can actually support.
     *
     * Stock is summed per SKU in a subquery before the price is applied.
     * `buyabans_stock_levels` holds a row per SKU *per inventory source*, and
     * the arithmetic happens to survive that either way — `SUM(qty * price)`
     * over four rows equals `price * SUM(qty)`. It is written this way because
     * that equivalence holds only while the price is a per-SKU constant, and a
     * per-source or dated price would break it silently.
     *
     * @return array{value: float, delta: null, trend: list<float>}|null
     */
    private function stockValue(): ?array
    {
        $perSku = DB::table('buyabans_stock_levels')
            ->selectRaw('sku_id, SUM(qty) AS qty')
            ->whereNotNull('sku_id')
            ->groupBy('sku_id');

        $value = (float) DB::query()
            ->fromSub($perSku, 'stock')
            ->join('skus', 'skus.id', '=', 'stock.sku_id')
            ->where('skus.selling_price', '>', 0)
            ->sum(DB::raw('stock.qty * skus.selling_price'));

        if ($value <= 0.0) {
            return null;
        }

        return ['value' => round($value, 2), 'delta' => null, 'trend' => []];
    }

    /**
     * Days of cover per SKU, bucketed into the bands above.
     *
     * Measured **only over SKUs that sold in the window**. A SKU with stock and
     * no recent sales has no measurable rate of sale, and counting it as
     * "over six months of cover" would report this dataset's shape rather than
     * the business's: demand was generated for 163 SKUs out of a 10,892-SKU
     * catalog, so every untouched SKU would land in the same band and the chart
     * would read as ten thousand lines of dead stock. The page says which
     * population it is describing.
     *
     * **These bands mix real stock with generated demand, and the two were
     * never reconciled.** Stock levels are the back office's own figures; the
     * order history was seeded (see `server_architecture.md`), and the seeder
     * writes orders without ever decrementing stock. A line can therefore show
     * a month of steady sales beside a stock row that has read zero throughout.
     * The distribution demonstrates the calculation; it is not a statement
     * about a real stock position. Same caveat as {@see stockCoverage()}.
     *
     * Two queries rather than a join, for the fan-out reason on
     * {@see stockCoverage()}, and bucketed in PHP so the SQL stays portable.
     *
     * @return array{counts: array<string, int>, skus: int}|null
     */
    private function coverDistribution(?CarbonImmutable $lastDay): ?array
    {
        if ($lastDay === null) {
            return null;
        }

        $sold = $this->demandQuery()
            ->whereNotNull('sku_id')
            ->where('demand_date', '>=', $lastDay->subDays(self::WINDOW_DAYS - 1)->toDateString())
            ->where('demand_date', '<=', $this->endOfDay($lastDay->toDateString()))
            ->selectRaw('sku_id, SUM(sold_qty) AS units')
            ->groupBy('sku_id')
            ->pluck('units', 'sku_id');

        if ($sold->isEmpty()) {
            return null;
        }

        $stock = DB::table('buyabans_stock_levels')
            ->whereIn('sku_id', $sold->keys())
            ->selectRaw('sku_id, SUM(qty) AS qty')
            ->groupBy('sku_id')
            ->pluck('qty', 'sku_id');

        $counts = array_fill_keys(array_column(self::COVER_BANDS, 'label'), 0);
        $measured = 0;

        foreach ($sold as $skuId => $units) {
            $rate = (float) $units / self::WINDOW_DAYS;

            if ($rate <= 0.0) {
                continue;
            }

            $measured++;
            $counts[$this->coverBand((float) ($stock[$skuId] ?? 0) / $rate)]++;
        }

        return $measured === 0 ? null : ['counts' => $counts, 'skus' => $measured];
    }

    /**
     * Which band a days-of-cover figure falls in.
     */
    private function coverBand(float $days): string
    {
        foreach (self::COVER_BANDS as $band) {
            if ($band['upToDays'] === null || $days <= $band['upToDays']) {
                return $band['label'];
            }
        }

        return self::COVER_BANDS[array_key_last(self::COVER_BANDS)]['label'];
    }

    /**
     * The cover distribution as chart bars, bands in order and slots fixed.
     *
     * Empty bands are kept. "No line is running out this week" is a real
     * answer, and dropping the bar would make the chart change shape week to
     * week for a reason the reader cannot see.
     *
     * @param  array{counts: array<string, int>, skus: int}|null  $cover
     * @return array{items: list<array{label: string, value: float, colorSlot: int}>, skus: int}|null
     */
    private function coverHealth(?array $cover): ?array
    {
        if ($cover === null) {
            return null;
        }

        return [
            'items' => array_map(
                fn (array $band): array => [
                    'label' => $band['label'],
                    'value' => (float) $cover['counts'][$band['label']],
                    'colorSlot' => $band['slot'],
                ],
                self::COVER_BANDS,
            ),
            'skus' => $cover['skus'],
        ];
    }

    /**
     * Lines that sold in the window and have nothing left in stock.
     *
     * The one number on this page that reads as a to-do list rather than a
     * measurement: every one of these is a product with demand on record and
     * nothing on hand.
     *
     * **Read it as a demonstration, not a work queue, while the demand is
     * seeded.** Measured on the current dataset: of 475 SKUs that sold in the
     * window, 321 have a back office stock row that genuinely reads zero — not
     * a missing row, a real zero. That figure is true of the data and still
     * means little, because the sales that "proved" the demand were generated
     * and never decremented the stock they are being compared against. See
     * {@see coverDistribution()}.
     *
     * @param  array{counts: array<string, int>, skus: int}|null  $cover
     * @return array{value: int, delta: null, trend: list<float>}|null
     */
    private function outOfStockLines(?array $cover): ?array
    {
        if ($cover === null) {
            return null;
        }

        return [
            'value' => $cover['counts'][self::COVER_BANDS[0]['label']],
            'delta' => null,
            'trend' => [],
        ];
    }

    /**
     * SKUs this system actually tracks demand for — not the catalog size.
     *
     * The catalog holds ~10,800 SKUs; only the ones with synced demand history
     * are forecast, and that is the number worth showing on a forecasting
     * dashboard.
     *
     * @return array{value: int, delta: float|null, trend: list<float>}|null
     */
    private function skusTracked(?CarbonImmutable $lastDay): ?array
    {
        if ($lastDay === null) {
            return null;
        }

        $value = $this->countDistinctSkus($this->demandQuery());

        if ($value === 0) {
            return null;
        }

        $trend = [];

        for ($week = self::TREND_WEEKS - 1; $week >= 0; $week--) {
            $end = $lastDay->subWeeks($week);
            $start = $end->subDays(6);

            $trend[] = (float) $this->countDistinctSkus(
                $this->demandQuery()
                    ->where('demand_date', '>=', $start->toDateString())
                    ->where('demand_date', '<=', $this->endOfDay($end->toDateString()))
            );
        }

        return [
            'value' => $value,
            'delta' => $this->percentChange(
                array_slice($trend, -4),
                array_slice($trend, -8, 4),
            ),
            'trend' => $trend,
        ];
    }

    /**
     * Deliberately null until a forecast has actually been scored.
     *
     * `forecast_accuracy` is populated by `app:score-forecast-accuracy`, which
     * can only grade a forecast once its horizon has elapsed. The table has
     * never held a row, so there is no accuracy to report and the tile shows
     * "—". Returning a placeholder — last run's confidence, say, or 100 minus
     * some error proxy — would put a number on the dashboard that nobody could
     * trace to a scored prediction.
     *
     * @return array{value: float, delta: float|null, trend: list<float>}|null
     */
    private function forecastAccuracy(): ?array
    {
        $scored = DB::table('forecast_accuracy')->count();

        if ($scored === 0) {
            return null;
        }

        $weekly = DB::table('forecast_accuracy')
            ->selectRaw('YEARWEEK(scored_at, 3) AS bucket, AVG(100 - LEAST(ABS(percentage_error), 100)) AS accuracy')
            ->whereNotNull('percentage_error')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->limit(self::TREND_WEEKS)
            ->pluck('accuracy')
            ->map(fn ($value) => round((float) $value, 1))
            ->all();

        if ($weekly === []) {
            return null;
        }

        return [
            'value' => (float) round((float) end($weekly), 1),
            'delta' => $this->percentChange(array_slice($weekly, -4), array_slice($weekly, -8, 4)),
            'trend' => $weekly,
        ];
    }

    /**
     * Purchase recommendations awaiting a human decision.
     *
     * @return array{value: int, delta: null, trend: list<float>}|null
     */
    private function reorderAlerts(): ?array
    {
        $open = InventoryRecommendation::query()
            ->where('recommendation_type', 'PURCHASE')
            ->whereIn('status', ['NEW', 'PENDING'])
            ->count();

        if ($open === 0 && InventoryRecommendation::query()->count() === 0) {
            // No engine run yet — absent, rather than a confident zero.
            return null;
        }

        return ['value' => $open, 'delta' => null, 'trend' => []];
    }

    /**
     * Days the current stock would last at the recent rate of sale.
     *
     * Measured only over SKUs that have **both** stock and measured demand.
     * Dividing total catalog stock by the demand of the few hundred pairs that
     * actually sell would produce a number in the hundreds of days that means
     * nothing.
     *
     * **This tile mixes real stock with seeded demand.** Stock levels are the
     * back office's own figures; the demand history was generated (see
     * `server_architecture.md` §10). The number therefore demonstrates that the
     * calculation works — it is not a statement about how long real stock would
     * really last.
     *
     * @return array{value: int, delta: null, trend: list<float>}|null
     */
    private function stockCoverage(?CarbonImmutable $lastDay): ?array
    {
        if ($lastDay === null) {
            return null;
        }

        $since = $lastDay->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $to = $lastDay->toDateString();

        // Deliberately two queries rather than a join. `buyabans_stock_levels`
        // holds a row per SKU *per inventory source* — up to four — so joining
        // demand to it multiplies every sold_qty by the number of sources that
        // SKU is stocked in. Measured here: 83,977 units instead of 27,135, and
        // a cover figure of 1 day instead of 5.
        $skuIds = $this->demandQuery()
            ->whereNotNull('sku_id')
            ->where('demand_date', '>=', $since)
            ->where('demand_date', '<=', $this->endOfDay($to))
            ->distinct()
            ->pluck('sku_id');

        if ($skuIds->isEmpty()) {
            return null;
        }

        $sold = (float) $this->demandQuery()
            ->whereIn('sku_id', $skuIds)
            ->where('demand_date', '>=', $since)
            ->where('demand_date', '<=', $this->endOfDay($to))
            ->sum('sold_qty');

        if ($sold <= 0.0) {
            return null;
        }

        $stock = (float) DB::table('buyabans_stock_levels')
            ->whereIn('sku_id', $skuIds)
            ->sum('qty');

        return [
            'value' => (int) round($stock / ($sold / self::WINDOW_DAYS)),
            'delta' => null,
            'trend' => [],
        ];
    }

    /**
     * Units sold per week over the trailing window.
     *
     * One series, not two. The card once promised "units forecast against units
     * sold", but a forecast here is a single total per pair over a 30-day
     * horizon, not a weekly figure — splitting it into weeks to fill a chart
     * would be drawing a shape the model never produced.
     *
     * @param  list<array{label: string, units: float, revenue: float, orders: float}>  $weeks
     * @return array{labels: list<string>, series: list<array{name: string, values: list<float>}>}
     */
    private function demandTrend(array $weeks): array
    {
        if ($weeks === []) {
            return ['labels' => [], 'series' => []];
        }

        return [
            'labels' => array_column($weeks, 'label'),
            'series' => [[
                'name' => 'Units sold',
                'values' => array_map(fn (array $w): float => round($w['units']), $weeks),
            ]],
        ];
    }

    /**
     * Revenue per week — a second chart, deliberately, not a second axis.
     *
     * Units and money are different scales, and putting them on one plot with
     * two y-axes lets the drawing imply any relationship you like by choosing
     * the scales. Side by side, the reader compares the two shapes themselves.
     *
     * @param  list<array{label: string, units: float, revenue: float, orders: float}>  $weeks
     * @return array{labels: list<string>, series: list<array{name: string, values: list<float>}>}
     */
    private function revenueTrend(array $weeks): array
    {
        if ($weeks === []) {
            return ['labels' => [], 'series' => []];
        }

        return [
            'labels' => array_column($weeks, 'label'),
            'series' => [[
                'name' => 'Revenue',
                'values' => array_map(fn (array $w): float => round($w['revenue']), $weeks),
            ]],
        ];
    }

    /**
     * The highest-volume SKUs of the trailing window.
     *
     * @return list<array{label: string, value: float}>
     */
    private function topMovers(?CarbonImmutable $lastDay): array
    {
        if ($lastDay === null) {
            return [];
        }

        $since = $lastDay->subDays(self::WINDOW_DAYS - 1)->toDateString();

        return DB::table('buyabans_daily_demands as d')
            // Labelled by product, not by SKU code. Many codes here are raw
            // barcodes — "6941856930605" tells a reader nothing, and a chart
            // axis of them is unreadable.
            ->leftJoin('skus as s', 's.id', '=', 'd.sku_id')
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->where('d.grain', $this->grain())
            ->where('d.demand_date', '>=', $since)
            ->where('d.demand_date', '<=', $this->endOfDay($lastDay->toDateString()))
            ->selectRaw('d.sku_code, MAX(p.name) AS product_name, SUM(d.sold_qty) AS units')
            ->groupBy('d.sku_code')
            ->orderByDesc('units')
            ->limit(self::TOP_MOVERS)
            ->get()
            ->map(fn ($row) => [
                'label' => $this->shortLabel($row->product_name, (string) $row->sku_code),
                'value' => (float) round((float) $row->units),
            ])
            ->all();
    }

    /**
     * Where the money comes from, by product category.
     *
     * Ranked by revenue and not by units on purpose: the two orders differ
     * sharply in this data — one category leads on volume with a fraction of
     * another's takings — and revenue is the one a buying decision is made
     * against. The units ranking is already on this page as top movers.
     *
     * @return list<array{label: string, value: float}>
     */
    private function revenueByCategory(?CarbonImmutable $lastDay): array
    {
        if ($lastDay === null) {
            return [];
        }

        return DB::table('buyabans_daily_demands as d')
            ->join('skus as s', 's.id', '=', 'd.sku_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('d.grain', $this->grain())
            ->where('d.demand_date', '>=', $lastDay->subDays(self::WINDOW_DAYS - 1)->toDateString())
            ->where('d.demand_date', '<=', $this->endOfDay($lastDay->toDateString()))
            ->selectRaw('c.name AS category, SUM(d.revenue) AS revenue')
            ->groupBy('c.name')
            ->orderByDesc('revenue')
            ->limit(self::TOP_CATEGORIES)
            ->get()
            ->map(fn ($row) => [
                'label' => $this->shortLabel((string) $row->category, 'Uncategorised'),
                'value' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Where it is selling, by warehouse.
     *
     * Only meaningful at the warehouse grain — at 'channel' or 'national' the
     * rows carry no warehouse, and the chart is correctly empty rather than
     * quietly aggregating everything into one unlabelled bar.
     *
     * @return list<array{label: string, value: float}>
     */
    private function demandByLocation(?CarbonImmutable $lastDay): array
    {
        if ($lastDay === null) {
            return [];
        }

        return DB::table('buyabans_daily_demands as d')
            ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->where('d.grain', $this->grain())
            ->where('d.demand_date', '>=', $lastDay->subDays(self::WINDOW_DAYS - 1)->toDateString())
            ->where('d.demand_date', '<=', $this->endOfDay($lastDay->toDateString()))
            ->selectRaw('w.name AS warehouse, SUM(d.revenue) AS revenue')
            ->groupBy('w.name')
            ->orderByDesc('revenue')
            ->limit(self::TOP_LOCATIONS)
            ->get()
            ->map(fn ($row) => [
                'label' => $this->shortLabel((string) $row->warehouse, 'Unassigned'),
                'value' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Whether the forecasts on this page can be trusted, and how fresh they are.
     *
     * A forecast with no run date behind it is a number of unknown age, and age
     * is the thing that quietly invalidates one.
     *
     * @return array{latestRun: array{finishedAt: string|null, status: string, horizonDays: int, forecasts: int}|null}
     */
    private function forecastStatus(): array
    {
        $run = DB::table('ml_forecast_runs')->orderByDesc('id')->first();

        if ($run === null) {
            return ['latestRun' => null];
        }

        return [
            'latestRun' => [
                'finishedAt' => $run->finished_at === null
                    ? null
                    : CarbonImmutable::parse($run->finished_at)->format('d M Y, H:i'),
                'status' => (string) $run->status,
                'horizonDays' => (int) $run->horizon_days,
                'forecasts' => DB::table('forecasts')->where('forecast_run_id', $run->id)->count(),
            ],
        ];
    }

    /**
     * A short, readable label for a bar: trimmed to fit beside one, falling
     * back to the given placeholder when the name is missing or blank.
     */
    private function shortLabel(?string $name, string $fallback): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return $fallback;
        }

        return mb_strlen($name) > 28 ? mb_substr($name, 0, 27).'…' : $name;
    }

    /**
     * The inclusive end of a day, as a bound that keeps the index usable.
     *
     * **Why not `whereDate()`.** It wraps the column in `DATE()`, and MySQL
     * cannot use an index on a column inside a function. Measured on this
     * table: `DATE(demand_date)` turned a 34,114-row range scan into a
     * 632,315-row filtered scan — 18x the work, on every one of this page's
     * ~20 queries. At 163 tracked SKUs that was invisible. At 943,000 demand
     * rows it took `overview()` to 70 seconds and the page stopped rendering.
     *
     * **Why not a bare `<= $date` either.** That is what `whereDate()` was
     * defending against, and the trap is real. `demand_date` is a genuine MySQL
     * DATE column, so `<= '2026-09-04'` is exact in production — but the suite
     * runs on SQLite, which stores what Laravel wrote for a 'date' cast
     * ('2026-09-04 00:00:00') and compares it as text, where
     * `'2026-09-04 00:00:00' <= '2026-09-04'` is **false** and the window
     * silently loses its own last day. Six-day weeks, and a cover figure a day
     * out. The same trap is recorded for `snapshot_date` in
     * app_architecture.md §1h.
     *
     * Closing at 23:59:59 is correct on both — MySQL coerces it to the same
     * date, SQLite compares a string that sorts after midnight — and leaves the
     * column bare, so the index is still used.
     */
    private function endOfDay(string $date): string
    {
        return $date.' 23:59:59';
    }

    /**
     * How many distinct SKUs a demand query covers, counted through an index.
     *
     * `->distinct()->count('sku_id')` compiles to `COUNT(DISTINCT sku_id)`, and
     * MySQL will not use `buyabans_demand_grain_sku_index` for it — it prefers
     * the unique index that leads with `grain`, matches that prefix, then
     * de-duplicates every row it read. Measured at 942,873 rows: **8.92
     * seconds**, and the same query wrapped as a subquery is **0.44** — the
     * optimiser picks the covering index for the inner `DISTINCT` and never
     * touches the table.
     *
     * `FORCE INDEX` would also work and is not used: it is MySQL-only syntax,
     * and the test suite runs on SQLite. This formulation is portable and needs
     * no hint.
     */
    private function countDistinctSkus(Builder $query): int
    {
        return (int) DB::query()
            ->fromSub($query->select('sku_id')->distinct(), 'tracked')
            ->count();
    }

    /**
     * Demand rows at the configured grain. Rows of different grains describe
     * the same sales, so summing across them would double-count.
     */
    private function demandQuery(): Builder
    {
        return DB::table('buyabans_daily_demands')->where('grain', $this->grain());
    }

    private function grain(): string
    {
        return (string) config('services.buyabans.grain', 'warehouse');
    }

    /**
     * The last day demand has been synced for. Every window is anchored to it
     * rather than to today: the dashboard should describe the data that exists,
     * not show a fortnight of zeros because the sync is behind.
     */
    private function lastDemandDate(): ?CarbonImmutable
    {
        if (MlTrainingDataService::demandSource() !== MlTrainingDataService::SOURCE_BUYABANS) {
            $last = DB::table('inventory_daily_snapshots')->max('snapshot_date');

            return $last === null ? null : CarbonImmutable::parse($last);
        }

        $last = $this->demandQuery()->max('demand_date');

        return $last === null ? null : CarbonImmutable::parse($last);
    }

    /**
     * Percentage change between two equal-length windows, or null when there is
     * not enough history to compare.
     *
     * @param  list<float>  $recent
     * @param  list<float>  $previous
     */
    private function percentChange(array $recent, array $previous): ?float
    {
        if ($recent === [] || $previous === []) {
            return null;
        }

        $before = array_sum($previous);

        if ($before <= 0.0) {
            return null;
        }

        return round(((array_sum($recent) - $before) / $before) * 100, 1);
    }
}
