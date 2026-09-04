<?php

declare(strict_types=1);

namespace Domain\Services\ForecastService;

use App\Enums\ForecastSource;
use App\Models\Forecast;
use Carbon\CarbonImmutable;
use Domain\Services\ForecastRunService\ForecastRunService;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only — forecasts are only ever written by
 * {@see ForecastRunService::processRun()}.
 * {@see scoreAccuracy()} is this module's one write path: once a forecast's
 * window has fully elapsed, it looks up what actually sold (from Phase 4's
 * `inventory_daily_snapshots`) and records the error (app_plan.md §39).
 */
final class ForecastService
{
    /** Weeks of actual sales shown behind the forecast. */
    private const HISTORY_WEEKS = 12;

    /** How many products the top-products chart ranks. */
    private const TOP_PRODUCTS = 8;

    public function __construct(private Forecast $model) {}

    /**
     * A plain-language summary of the latest forecast run, for the page header
     * and charts.
     *
     * **Written for someone who does not know what a forecast is.** The table
     * below it carries the per-row detail; this answers the three questions a
     * reader actually has — how much do we expect to sell, is that more or less
     * than we just sold, and how sure are we.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $runId = DB::table('forecasts')->max('forecast_run_id');

        if ($runId === null) {
            return ['summary' => null, 'chart' => ['labels' => [], 'series' => []], 'topProducts' => []];
        }

        $totals = DB::table('forecasts')
            ->where('forecast_run_id', $runId)
            ->selectRaw('COUNT(*) AS rows_count, COUNT(DISTINCT sku_id) AS products')
            ->selectRaw('SUM(predicted_qty) AS predicted, SUM(lower_qty) AS lower, SUM(upper_qty) AS upper')
            ->selectRaw('AVG(confidence_score) AS confidence, MAX(horizon_days) AS horizon, MAX(forecast_date) AS forecast_date')
            ->first();

        $horizon = (int) ($totals->horizon ?? 30);
        $previous = $this->soldInPreviousWindow($horizon);
        $predicted = (float) ($totals->predicted ?? 0);

        return [
            'summary' => [
                'products' => (int) ($totals->products ?? 0),
                'forecasts' => (int) ($totals->rows_count ?? 0),
                'horizonDays' => $horizon,
                'forecastDate' => $totals->forecast_date,
                'expectedUnits' => round($predicted),
                'rangeLow' => round((float) ($totals->lower ?? 0)),
                'rangeHigh' => round((float) ($totals->upper ?? 0)),
                'previousUnits' => round($previous),
                // Null rather than a made-up 0% when there is nothing to compare
                // against — a "0% change" reads as a finding, an absence does not.
                'changePercent' => $previous > 0
                    ? round((($predicted - $previous) / $previous) * 100, 1)
                    : null,
                'confidence' => $this->confidenceBand((float) ($totals->confidence ?? 0)),
                'basis' => $this->basisBreakdown($runId),
            ],
            'chart' => $this->historyAndForecast($runId, $horizon),
            'topProducts' => $this->topProducts($runId),
        ];
    }

    /**
     * Units actually sold in the window immediately before the forecast — the
     * only honest yardstick for "is this a lot?".
     */
    private function soldInPreviousWindow(int $horizonDays): float
    {
        if (MlTrainingDataService::demandSource() !== MlTrainingDataService::SOURCE_BUYABANS) {
            $last = DB::table('inventory_daily_snapshots')->max('snapshot_date');

            return $last === null ? 0.0 : (float) DB::table('inventory_daily_snapshots')
                ->where('snapshot_date', '>', $this->endOfDay(CarbonImmutable::parse($last)->subDays($horizonDays)->toDateString()))
                ->sum('sold_qty');
        }

        $grain = (string) config('services.buyabans.grain', 'warehouse');
        $last = DB::table('buyabans_daily_demands')->where('grain', $grain)->max('demand_date');

        if ($last === null) {
            return 0.0;
        }

        return (float) DB::table('buyabans_daily_demands')
            ->where('grain', $grain)
            ->where('demand_date', '>', $this->endOfDay(CarbonImmutable::parse($last)->subDays($horizonDays)->toDateString()))
            ->sum('sold_qty');
    }

    /**
     * A confidence score turned into a word.
     *
     * "67" means nothing to a reader who does not know the scale; "Medium
     * confidence" does. The number rides along for anyone who wants it.
     *
     * @return array{score: int, label: string, tone: string, explanation: string}
     */
    private function confidenceBand(float $score): array
    {
        $rounded = (int) round($score);

        return match (true) {
            $rounded >= 70 => [
                'score' => $rounded,
                'label' => 'High',
                'tone' => 'success',
                'explanation' => 'These products sell steadily, so the forecast is unlikely to be far out.',
            ],
            $rounded >= 40 => [
                'score' => $rounded,
                'label' => 'Medium',
                'tone' => 'warning',
                'explanation' => 'Sales vary week to week, so treat the range as the realistic span.',
            ],
            default => [
                'score' => $rounded,
                'label' => 'Low',
                'tone' => 'danger',
                'explanation' => 'Sales are irregular or sparse. Use the range, not the single number.',
            ],
        };
    }

    /**
     * What each forecast was based on, in plain English and ordered by share.
     *
     * @return list<array{label: string, explanation: string, count: int, share: float}>
     */
    private function basisBreakdown(int $runId): array
    {
        $rows = DB::table('forecasts')
            ->where('forecast_run_id', $runId)
            ->selectRaw('forecast_source, COUNT(*) AS total')
            ->groupBy('forecast_source')
            ->orderByDesc('total')
            ->get();

        $all = max(1, (int) $rows->sum('total'));

        return $rows->map(function ($row) use ($all) {
            $source = ForecastSource::tryFrom((string) $row->forecast_source);

            return [
                'label' => $source?->label() ?? (string) $row->forecast_source,
                'explanation' => $source?->explanation() ?? '',
                'count' => (int) $row->total,
                'share' => round(((int) $row->total / $all) * 100, 1),
            ];
        })->all();
    }

    /**
     * Twelve weeks of what actually sold, then the forecast for the weeks ahead
     * with its range.
     *
     * The forecast series repeats the final actual week so the two lines join
     * rather than floating apart — that point is a real observation, not an
     * invented one. Everything after it is the run's total spread evenly across
     * the horizon and **labelled as a weekly average**, because the model
     * produces one figure for the whole window and not a shape within it.
     *
     * @return array<string, mixed>
     */
    private function historyAndForecast(int $runId, int $horizonDays): array
    {
        $weeksAhead = max(1, (int) ceil($horizonDays / 7));
        $history = $this->weeklyHistory(self::HISTORY_WEEKS);

        if ($history === []) {
            return ['labels' => [], 'series' => []];
        }

        $totals = DB::table('forecasts')
            ->where('forecast_run_id', $runId)
            ->selectRaw('SUM(predicted_qty) AS predicted, SUM(lower_qty) AS lower, SUM(upper_qty) AS upper')
            ->first();

        $perWeek = fn (?float $total) => $total === null
            ? null
            : round(((float) $total) / max(1, $horizonDays) * 7);

        $labels = array_column($history, 'label');
        $sold = array_map(fn ($week) => (float) $week['units'], $history);
        $lastActualIndex = count($sold) - 1;

        $expected = array_fill(0, count($sold), null);
        $expected[$lastActualIndex] = $sold[$lastActualIndex];

        $lower = array_fill(0, count($sold), null);
        $upper = array_fill(0, count($sold), null);

        $cursor = CarbonImmutable::parse($history[$lastActualIndex]['start']);

        for ($week = 1; $week <= $weeksAhead; $week++) {
            $cursor = $cursor->addWeek();
            $labels[] = $cursor->format('d M');
            $sold[] = null;
            $expected[] = $perWeek((float) ($totals->predicted ?? 0));
            $lower[] = $perWeek((float) ($totals->lower ?? 0));
            $upper[] = $perWeek((float) ($totals->upper ?? 0));
        }

        // The band starts where the forecast does, so it does not imply a range
        // around weeks that already happened.
        $lower[$lastActualIndex] = $sold[$lastActualIndex];
        $upper[$lastActualIndex] = $sold[$lastActualIndex];

        return [
            'labels' => $labels,
            'series' => [
                ['name' => 'Units sold', 'values' => $sold],
                ['name' => 'Expected (weekly average)', 'values' => $expected],
            ],
            'band' => ['lower' => $lower, 'upper' => $upper, 'seriesIndex' => 1],
        ];
    }

    /**
     * @return list<array{label: string, start: string, units: float}>
     */
    private function weeklyHistory(int $weeks): array
    {
        $grain = (string) config('services.buyabans.grain', 'warehouse');
        $buyabans = MlTrainingDataService::demandSource() === MlTrainingDataService::SOURCE_BUYABANS;

        $table = $buyabans ? 'buyabans_daily_demands' : 'inventory_daily_snapshots';
        $dateColumn = $buyabans ? 'demand_date' : 'snapshot_date';

        $base = fn () => $buyabans
            ? DB::table($table)->where('grain', $grain)
            : DB::table($table);

        $last = $base()->max($dateColumn);

        if ($last === null) {
            return [];
        }

        $lastDay = CarbonImmutable::parse($last);
        $history = [];

        for ($week = $weeks - 1; $week >= 0; $week--) {
            $end = $lastDay->subWeeks($week);
            $start = $end->subDays(6);

            $history[] = [
                'label' => $start->format('d M'),
                'start' => $start->toDateString(),
                'units' => round((float) $base()
                    ->where($dateColumn, '>=', $start->toDateString())
                    ->where($dateColumn, '<=', $this->endOfDay($end->toDateString()))
                    ->sum('sold_qty')),
            ];
        }

        return $history;
    }

    /**
     * The products expected to sell most, labelled by name rather than SKU.
     *
     * @return list<array{label: string, value: float}>
     */
    private function topProducts(int $runId): array
    {
        return DB::table('forecasts as f')
            ->join('skus as s', 's.id', '=', 'f.sku_id')
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->where('f.forecast_run_id', $runId)
            ->selectRaw('COALESCE(p.name, s.sku) AS name, SUM(f.predicted_qty) AS units')
            // Grouped by the expression, not the alias: MySQL's
            // only_full_group_by rejects an alias whose expression references
            // non-aggregated columns.
            ->groupByRaw('COALESCE(p.name, s.sku)')
            ->orderByDesc('units')
            ->limit(self::TOP_PRODUCTS)
            ->get()
            ->map(fn ($row) => [
                'label' => mb_strlen((string) $row->name) > 28
                    ? mb_substr((string) $row->name, 0, 27).'…'
                    : (string) $row->name,
                'value' => round((float) $row->units),
            ])
            ->all();
    }

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
            ->with(['warehouse:id,name', 'sku:id,sku,product_id', 'sku.product:id,name', 'accuracy', 'modelVersion:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sku_id', $skuId))
            ->when($filters['forecast_run_id'] ?? null, fn ($query, $runId) => $query
                ->where('forecast_run_id', $runId))
            // Default to the newest run. Every run re-forecasts the same pairs,
            // so listing them all shows each product once per run it has ever
            // been through — 7,833 rows describing 966 predictions, with no
            // indication that most of them are superseded. The summary above
            // the table describes the latest run, and now so does the table.
            ->when(
                ! ($filters['forecast_run_id'] ?? null) && ! ($filters['all_runs'] ?? false),
                fn ($query) => $query->where(
                    'forecast_run_id',
                    DB::table('forecasts')->max('forecast_run_id')
                )
            )
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($q) => $q->where('sku', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Score every forecast whose window has fully elapsed and hasn't been
     * scored yet: sum the actual `sold_qty` over the same
     * (forecast_date − horizon_days) → forecast_date window from
     * `inventory_daily_snapshots`, and record the error.
     *
     * @return array<string, mixed>
     */
    public function scoreAccuracy(): array
    {
        try {
            $due = $this->model->newQuery()
                ->whereDoesntHave('accuracy')
                ->where('forecast_date', '<=', $this->endOfDay(now()->toDateString()))
                ->get();

            $scored = 0;

            foreach ($due as $forecast) {
                $windowStart = $forecast->forecast_date->copy()->subDays($forecast->horizon_days);

                $actualQty = (float) DB::table('inventory_daily_snapshots')
                    ->where('warehouse_id', $forecast->warehouse_id)
                    ->where('sku_id', $forecast->sku_id)
                    ->where('snapshot_date', '>=', $windowStart->toDateString())
                    ->where('snapshot_date', '<=', $this->endOfDay($forecast->forecast_date->toDateString()))
                    ->sum('sold_qty');

                $predicted = (float) $forecast->predicted_qty;
                $absoluteError = abs($predicted - $actualQty);

                $forecast->accuracy()->create([
                    'actual_qty' => $actualQty,
                    'absolute_error' => $absoluteError,
                    'percentage_error' => $actualQty > 0 ? round($absoluteError / $actualQty * 100, 2) : null,
                    'calculated_at' => now(),
                ]);

                $scored++;
            }

            return ['success' => true, 'message' => "Scored {$scored} forecast(s)", 'data' => ['scored' => $scored]];
        } catch (Throwable $exception) {
            Log::error('Failed scoring forecast accuracy', ['exception' => $exception->getMessage()]);

            return ['success' => false, 'message' => 'Error scoring forecast accuracy'];
        }
    }

    /**
     * The inclusive end of a day, as a bound that keeps the index usable.
     *
     * `whereDate()` wraps the column in `DATE()`, and MySQL cannot use an index
     * on a column inside a function. Measured on this page: 20 queries, 17.2
     * seconds, each scanning ~632,000 rows instead of ranging over ~34,000.
     *
     * A bare `<= $date` is not the answer either — it silently drops the last
     * day on SQLite, which is what the suite runs on. The full reasoning, and
     * the regression test that pins it, are on
     * {@see DashboardService::endOfDay()}.
     *
     * Note the `>` case: `DATE(col) > '2026-08-05'` means "from the 6th",
     * so the plain form must exclude the whole of the 5th — `> endOfDay()`,
     * not `> $date`.
     */
    private function endOfDay(string $date): string
    {
        return $date.' 23:59:59';
    }
}
