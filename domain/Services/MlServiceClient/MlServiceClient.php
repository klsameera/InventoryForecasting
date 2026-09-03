<?php

declare(strict_types=1);

namespace Domain\Services\MlServiceClient;

use App\Enums\ForecastSource;
use App\Enums\PromotionDiscountType;
use Carbon\CarbonImmutable;
use Domain\Services\DemandProfileService\DemandProfileService;
use Domain\Services\ForecastRunService\ForecastRunService;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Laravel side of app_plan.md §33/§34: prepares a compact feature
 * payload and POSTs it to the Python service's `/forecast/run` (never the
 * other way around — Python never queries Laravel's database directly, see
 * the ml-service README).
 *
 * The Python side serves four algorithms: two statistical baselines
 * (`ewma`, `seasonal_naive`) that need nothing but the daily series, and two
 * trained neural checkpoints (`tft`, `deepar`) that additionally need the
 * covariate block {@see buildNeuralFeatures()} assembles. Python has no
 * opinion on *which* algorithm to run or on *why* a particular series was
 * chosen — {@see ForecastRunService} decides, per pair, whether to send the
 * SKU's own history or a {@see DemandProfileService} fallback
 * (app_architecture.md §1j), and stamps the resulting {@see ForecastSource}
 * accordingly when persisting each forecast.
 *
 * **The features block is all-or-nothing by design.** A neural model given a
 * partially-filled feature set does not return a slightly worse forecast; it
 * returns one computed from the claim that this SKU costs nothing, belongs to
 * no category and was never out of stock. So it is built only for pairs whose
 * selected algorithm actually needs it, and built completely when it is.
 *
 * This Service has no constructor-injected model — like
 * `InventoryAnalyticsService` (§1g), it's infrastructure, not a CRUD
 * domain owner.
 */
final class MlServiceClient
{
    /**
     * How many days of daily-sold-quantity history to send per SKU — capped
     * at the longest supported forecast horizon (app_plan.md §37) so the
     * payload stays a compact, prepared feature set rather than raw ledger
     * data (§35). Also the window {@see DemandProfileService}
     * builds its fallback series over, so a SKU's own series and any
     * fallback/blended series stay the same length and calendar range.
     */
    public const HISTORY_DAYS = 180;

    /**
     * The fixed decoder length the neural checkpoints were trained with
     * (`MAX_PREDICTION_LENGTH` in `ml-service/training/dataset.py`). A trained
     * model always emits this many days regardless of the horizon asked for —
     * Python truncates to the requested horizon — so the known-future
     * covariates must cover at least this far or the decoder reads zeros for
     * days it was told nothing about.
     */
    public const NEURAL_DECODER_DAYS = 30;

    /**
     * Algorithms that resolve to a trained checkpoint rather than a formula,
     * and therefore require {@see buildNeuralFeatures()}. Mirrors
     * `NEURAL_ALGORITHMS` in `ml-service/app/schemas.py`.
     *
     * @var list<string>
     */
    public const NEURAL_ALGORITHMS = ['tft', 'deepar'];

    /**
     * @param  array<int, array{warehouse_id: int, sku_id: int}>  $pairs
     * @return array<int, array{warehouse_id: int, sku_id: int, predicted_qty: float, lower_qty: float, upper_qty: float, confidence_score: int, forecast_source: string}>
     *
     * @throws RuntimeException if the ML service is unreachable or returns an error
     */
    public function requestForecast(array $pairs, int $horizonDays): array
    {
        return $this->send($this->buildSeries($pairs), $horizonDays);
    }

    /**
     * The raw HTTP call, taking an already-built series — used directly by
     * {@see ForecastRunService} when one
     * or more pairs need their series overridden with a cold-start/hybrid
     * fallback (app_architecture.md §1j) rather than each pair's own
     * SKU-history series.
     *
     * @param  array<int, array{warehouse_id: int, sku_id: int, daily_sold_qty: array<int|float>}>  $series
     * @return array<int, array{warehouse_id: int, sku_id: int, predicted_qty: float, lower_qty: float, upper_qty: float, confidence_score: int, forecast_source: string}>
     *
     * @throws RuntimeException if the ML service is unreachable or returns an error
     */
    public function send(array $series, int $horizonDays): array
    {
        $baseUrl = rtrim((string) config('services.ml.url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('ML_SERVICE_URL is not configured');
        }

        $token = config('services.ml.token');

        $response = Http::baseUrl($baseUrl)
            ->timeout($this->timeoutFor($series))
            ->acceptJson()
            ->when($token, fn ($http) => $http->withToken($token))
            ->post('/forecast/run', [
                'horizon_days' => $horizonDays,
                'series' => $series,
            ]);

        if ($response->failed()) {
            Log::error('ML service request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("ML service returned HTTP {$response->status()}");
        }

        return $response->json('results') ?? [];
    }

    /**
     * How long to wait on `/forecast/run`, in seconds.
     *
     * The two timeouts differ by an order of magnitude because the work does.
     * A baseline batch is arithmetic over a list and returns in well under a
     * second; a neural batch loads a checkpoint on first use and runs a real
     * forward pass over every series. A 441-pair run against the TFT exceeded
     * the original flat 30s and the whole run was marked Failed — which is how
     * this came to be configurable rather than a constant.
     *
     * Kept as low as it can be for each case rather than raised globally: a
     * long timeout on the baseline path would turn a hung service into a
     * five-minute stall instead of a fast, obvious failure.
     *
     * @param  array<int, array<string, mixed>>  $series
     */
    private function timeoutFor(array $series): int
    {
        foreach ($series as $entry) {
            if (in_array($entry['algorithm'] ?? null, self::NEURAL_ALGORITHMS, true)) {
                return (int) config('services.ml.neural_timeout', 300);
            }
        }

        return (int) config('services.ml.timeout', 30);
    }

    /**
     * Algorithm names the service reports it can actually serve right now.
     *
     * The two baselines are always available; a neural one is only available
     * where a checkpoint and its `dataset_params.pt` are both present and
     * loadable.
     *
     * This is a **diagnostic**, surfaced by `app:forecast-model-status`, not a
     * pre-flight check on the forecast path. Gating algorithm selection on it
     * was tried and removed: it made every run depend on an extra HTTP call
     * and bought nothing, because the service already refuses a model it
     * cannot run and answers with a baseline, echoing what it really used.
     *
     * @return list<string>
     *
     * @throws RuntimeException if the ML service is unreachable
     */
    public function availableAlgorithms(): array
    {
        $baseUrl = rtrim((string) config('services.ml.url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('ML_SERVICE_URL is not configured');
        }

        $token = config('services.ml.token');

        $response = Http::baseUrl($baseUrl)
            ->timeout(10)
            ->acceptJson()
            ->when($token, fn ($http) => $http->withToken($token))
            ->get('/models');

        if ($response->failed()) {
            throw new RuntimeException("ML service returned HTTP {$response->status()} for /models");
        }

        $algorithms = [];

        foreach ((array) $response->json('algorithms', []) as $name => $details) {
            if (($details['available'] ?? false) === true) {
                $algorithms[] = (string) $name;
            }
        }

        return $algorithms;
    }

    /**
     * @param  array<int, array{warehouse_id: int, sku_id: int}>  $pairs
     * @return array<int, array{warehouse_id: int, sku_id: int, daily_sold_qty: array<int, int>}>
     */
    public function buildSeries(array $pairs): array
    {
        $since = now()->subDays(self::HISTORY_DAYS)->toDateString();

        return array_map(function (array $pair) use ($since) {
            $history = DB::table('inventory_daily_snapshots')
                ->where('warehouse_id', $pair['warehouse_id'])
                ->where('sku_id', $pair['sku_id'])
                ->whereDate('snapshot_date', '>=', $since)
                ->orderBy('snapshot_date')
                ->pluck('sold_qty')
                ->map(fn ($qty) => (int) $qty)
                ->all();

            return [
                'warehouse_id' => $pair['warehouse_id'],
                'sku_id' => $pair['sku_id'],
                'daily_sold_qty' => $history,
            ];
        }, $pairs);
    }

    /**
     * The covariate block the trained models need, for one pair.
     *
     * Column-for-column the same features {@see MlTrainingDataService} exports
     * for training — that is the point. A model conditioned at training time on
     * `stockout_minutes` and asked at serving time to do without it is being
     * fed a different distribution than the one it learned, and nothing in the
     * stack would report an error.
     *
     * Returns `null` when the pair has no snapshot history in the window, so
     * the caller omits the block entirely rather than sending an empty one.
     *
     * `future_*` covers the forecast horizon: a retailer genuinely knows its
     * own promotion calendar in advance, which is exactly what makes these
     * legitimate known-future inputs rather than leakage. At least
     * {@see NEURAL_DECODER_DAYS} days are always sent, because the trained
     * decoder runs its full length regardless of the horizon requested.
     *
     * @param  array<int, list<array{start: string, end: string, discount: float}>>  $promotionWindows
     * @param  object{category_id: ?int, brand_id: ?int, selling_price: ?float}|null  $skuMeta
     * @return array<string, mixed>|null
     */
    public function buildNeuralFeatures(
        array $pair,
        array $promotionWindows,
        ?object $skuMeta,
        int $horizonDays
    ): ?array {
        $since = now()->subDays(self::HISTORY_DAYS)->toDateString();

        $snapshots = DB::table('inventory_daily_snapshots')
            ->where('warehouse_id', $pair['warehouse_id'])
            ->where('sku_id', $pair['sku_id'])
            ->whereDate('snapshot_date', '>=', $since)
            ->orderBy('snapshot_date')
            ->select([
                DB::raw('DATE(snapshot_date) as date'),
                'available_qty',
                'received_qty',
                'stockout_minutes',
            ])
            ->get();

        if ($snapshots->isEmpty()) {
            return null;
        }

        $skuId = (int) $pair['sku_id'];
        $available = [];
        $received = [];
        $stockoutMinutes = [];
        $onPromotion = [];
        $promotionDiscount = [];

        foreach ($snapshots as $snapshot) {
            $date = (string) $snapshot->date;

            $available[] = (float) $snapshot->available_qty;
            $received[] = (float) $snapshot->received_qty;
            $stockoutMinutes[] = (float) ($snapshot->stockout_minutes ?? 0);

            [$flag, $discount] = $this->promotionStateFor($promotionWindows, $skuId, $date);
            $onPromotion[] = (float) $flag;
            $promotionDiscount[] = $discount;
        }

        // The horizon starts the day after the last day we actually observed,
        // not tomorrow. The snapshot pipeline can be behind, and anchoring the
        // future window to today would leave a gap the Python side would have
        // to interpolate across — inventing history rather than forecasting.
        $lastObserved = (string) $snapshots->last()->date;
        $futureDays = max($horizonDays, self::NEURAL_DECODER_DAYS);
        $futurePromotion = [];
        $futureDiscount = [];

        for ($offset = 1; $offset <= $futureDays; $offset++) {
            $date = CarbonImmutable::parse($lastObserved)->addDays($offset)->toDateString();

            [$flag, $discount] = $this->promotionStateFor($promotionWindows, $skuId, $date);
            $futurePromotion[] = (float) $flag;
            $futureDiscount[] = $discount;
        }

        return [
            'series_start_date' => (string) $snapshots->first()->date,
            'category_id' => $skuMeta?->category_id === null ? null : (int) $skuMeta->category_id,
            'brand_id' => $skuMeta?->brand_id === null ? null : (int) $skuMeta->brand_id,
            'selling_price' => (float) ($skuMeta?->selling_price ?? 0),
            'available_qty' => $available,
            'received_qty' => $received,
            'stockout_minutes' => $stockoutMinutes,
            'on_promotion' => $onPromotion,
            'promotion_discount' => $promotionDiscount,
            'future_on_promotion' => $futurePromotion,
            'future_promotion_discount' => $futureDiscount,
        ];
    }

    /**
     * Category, brand and current price for every SKU in one query.
     *
     * Loaded in bulk rather than per pair: a forecast run covers every stocked
     * SKU, and this is static reference data that does not vary by warehouse.
     *
     * @param  array<int, int>  $skuIds
     * @return array<int, object{category_id: ?int, brand_id: ?int, selling_price: ?float}>
     */
    public function skuMetadata(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        return DB::table('skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereIn('skus.id', array_values(array_unique($skuIds)))
            ->select([
                'skus.id',
                'products.category_id',
                'products.brand_id',
                'skus.selling_price',
            ])
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Promotion windows keyed by SKU, loaded once for a whole run.
     *
     * Deliberately in memory rather than joined on a date-range predicate:
     * joining `promotion_skus` between dates fans out whenever two promotions
     * overlap on one SKU, which duplicates rows and corrupts the series. Same
     * trap, same treatment, as {@see MlTrainingDataService::promotionWindows()}
     * — and the same reason `discount_type` is compared against the enum's
     * backing value rather than a lowercase literal, which silently exported
     * every discount as 0.0 when it was got wrong there.
     *
     * @return array<int, list<array{start: string, end: string, discount: float}>>
     */
    public function promotionWindows(): array
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
