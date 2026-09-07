<?php

declare(strict_types=1);

namespace Domain\Services\ForecastRunService;

use App\Enums\ForecastMaturity;
use App\Enums\ForecastRunStatus;
use App\Enums\ForecastSource;
use App\Jobs\RunDemandForecast;
use App\Models\MlForecastRun;
use Domain\Facades\DemandProfileFacade\DemandProfileFacade;
use Domain\Facades\ForecastMaturityFacade\ForecastMaturityFacade;
use Domain\Facades\MlServiceClientFacade\MlServiceClientFacade;
use Domain\Facades\ModelSelectionFacade\ModelSelectionFacade;
use Domain\Services\DemandProfileService\DemandProfileService;
use Domain\Services\MlServiceClient\MlServiceClient;
use Domain\Services\MlTrainingDataService\MlTrainingDataService;
use Domain\Services\ModelSelectionService\ModelSelectionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Triggers and tracks forecast batches (app_plan.md §34, §67).
 * {@see store()} only creates the QUEUED run and dispatches the job — all
 * the actual forecasting work happens in {@see processRun()}, called from
 * {@see RunDemandForecast} rather than inline in the HTTP request,
 * matching §32's "scheduled bulk forecasting should run through background
 * workers rather than synchronous HTTP calls."
 *
 * {@see processRun()} is also where Phase 6's cold-start fallback
 * (app_architecture.md §1j) plugs in: each pair's {@see ForecastMaturity} is
 * classified before the series is sent, and a Cold-start/Early pair's series
 * is replaced/blended with a {@see DemandProfileService}
 * fallback rather than sending its own (empty or sparse) history.
 */
final class ForecastRunService
{
    public function __construct(private MlForecastRun $model) {}

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
            ->with('modelVersion:id,name,version')
            ->withCount('forecasts')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query
                ->where('status', $status))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?MlForecastRun
    {
        return $this->model->with('modelVersion')->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $run = $this->model->create([
                'warehouse_ids' => $data['warehouse_ids'] ?? null,
                'horizon_days' => $data['horizon_days'],
                'status' => ForecastRunStatus::Queued,
                'created_by' => $data['created_by'] ?? null,
            ]);

            DB::commit();

            RunDemandForecast::dispatch($run->id);

            return ['success' => true, 'message' => 'Forecast run queued', 'data' => $run];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed queueing forecast run', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error queueing forecast run'];
        }
    }

    /**
     * Queue a fresh run with an earlier run's settings.
     *
     * The original is never re-opened. A run is a point-in-time record of what
     * was asked for and what happened — including a refusal — so a retry is a
     * new record beside it rather than an edit that erases the first attempt.
     *
     * @return array{success: bool, message: string, data?: MlForecastRun}
     */
    public function retry(int $runId, ?int $userId = null): array
    {
        $previous = $this->model->find($runId);

        if ($previous === null) {
            return ['success' => false, 'message' => 'That forecast run no longer exists.'];
        }

        return $this->store([
            'horizon_days' => $previous->horizon_days,
            'warehouse_ids' => $previous->warehouse_ids,
            'created_by' => $userId,
        ]);
    }

    /**
     * The actual forecasting work — called from the queued job, not the
     * controller. Not wrapped in a single outer transaction: partial
     * progress (some forecasts persisted before a later failure) is more
     * useful here than an all-or-nothing rollback of a potentially large
     * batch, and the run's own status/error_message record exactly what
     * happened either way.
     */
    public function processRun(int $runId): void
    {
        $run = $this->model->findOrFail($runId);
        $run->update(['status' => ForecastRunStatus::Processing, 'started_at' => now()]);

        try {
            $pairs = $this->pairsInScope($run->warehouse_ids);

            if ($pairs === []) {
                $run->update(['status' => ForecastRunStatus::Completed, 'finished_at' => now()]);

                return;
            }

            [$series, $sourceByPair, $algorithmByPair] = $this->prepareSeries($pairs, $run->horizon_days);

            ['results' => $results, 'refusals' => $refusals] = MlServiceClientFacade::send($series, $run->horizon_days);

            $algorithmTally = [];

            foreach ($results as $result) {
                $key = $result['warehouse_id'].'-'.$result['sku_id'];

                // The algorithm the service ran, which is now always the one
                // that was asked for — nothing is substituted, so a row under
                // this name really came from this algorithm.
                $algorithm = $result['algorithm']
                    ?? $algorithmByPair[$key]
                    ?? ModelSelectionService::DEFAULT_ALGORITHM;

                $algorithmTally[$algorithm] = ($algorithmTally[$algorithm] ?? 0) + 1;

                $run->forecasts()->create([
                    'warehouse_id' => $result['warehouse_id'],
                    'sku_id' => $result['sku_id'],
                    'forecast_date' => now()->addDays($run->horizon_days)->toDateString(),
                    'horizon_days' => $run->horizon_days,
                    'predicted_qty' => $result['predicted_qty'],
                    'lower_qty' => $result['lower_qty'],
                    'upper_qty' => $result['upper_qty'],
                    'confidence_score' => $result['confidence_score'],
                    'model_version_id' => ModelSelectionFacade::modelVersionFor($algorithm)->id,
                    'forecast_source' => ($sourceByPair[$key] ?? ForecastSource::SkuHistory)->value,
                ]);
            }

            arsort($algorithmTally);
            $primaryAlgorithm = array_key_first($algorithmTally) ?? ModelSelectionService::DEFAULT_ALGORITHM;

            if ($refusals !== []) {
                Log::warning('A model declined part of a forecast run', [
                    'run_id' => $runId,
                    'refused' => count($refusals),
                    'served' => count($results),
                    'reasons' => array_slice(array_values(array_unique(
                        array_column($refusals, 'reason')
                    )), 0, 5),
                ]);
            }

            $run->update([
                // Nothing served at all is a failed run: the operator asked for
                // an algorithm and received none of it. A partial refusal still
                // completes — the pairs that were served hold real forecasts
                // from the algorithm that was actually requested — but carries
                // the refusal message so the page can offer a retry rather than
                // report a clean success over a hole in the results.
                'status' => $results === [] && $refusals !== []
                    ? ForecastRunStatus::Failed
                    : ForecastRunStatus::Completed,
                'error_message' => $this->refusalMessage($refusals),
                'finished_at' => now(),
                // Informational only — each Forecast row's own
                // model_version_id (set above, per pair) is authoritative.
                // A run that used both algorithms across different pairs
                // records whichever one covered the most pairs.
                'model_version_id' => ModelSelectionFacade::modelVersionFor($primaryAlgorithm)->id,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed processing forecast run', [
                'exception' => $exception->getMessage(),
                'run_id' => $runId,
            ]);

            $run->update([
                'status' => ForecastRunStatus::Failed,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The weight given to a SKU's own (sparse) series when blending it with
     * a peer-group fallback for an Early-maturity SKU — app_plan.md §29's
     * illustrative "10% SKU / 90% category+brand+seasonality" for brand-new
     * items, simplified to a single fixed split (this scaffold has no
     * separate seasonality signal to weight in) rather than the plan's more
     * elaborate multi-factor blend. Documented simplification, same
     * category as app_architecture.md §1g's basic reorder point.
     */
    private const EARLY_SKU_WEIGHT = 0.3;

    /**
     * Builds the per-pair series to send to the ML service, substituting or
     * blending in a {@see DemandProfileService}
     * fallback for any pair whose {@see ForecastMaturity} is Cold-start or
     * Early — app_architecture.md §1j. Also stamps each entry's `algorithm`
     * (Phase 10, app_architecture.md §1n) from
     * {@see ModelSelectionService::chooseAlgorithm()} — a Cold-start/Early
     * pair naturally has no accuracy history to compare algorithms from
     * either, so it falls back to {@see ModelSelectionService::DEFAULT_ALGORITHM}
     * the same as any other SKU without enough history yet.
     *
     * For an Established pair whose selected algorithm is a trained model, it
     * additionally attaches the covariate block
     * {@see MlServiceClient::buildNeuralFeatures()} builds. A Cold-start or
     * Early pair never gets one: its series has been substituted or blended
     * with *peer* demand, and pairing that with this SKU's own embedding,
     * price and category would describe one product using another's sales.
     * Those pairs are forced back to a baseline here rather than left for
     * Python to refuse, so the reason stays on the side that knows it.
     *
     * @param  array<int, array{warehouse_id: int, sku_id: int}>  $pairs
     * @return array{0: array<int, array{warehouse_id: int, sku_id: int, daily_sold_qty: list<float>, algorithm: string, features?: array<string, mixed>}>, 1: array<string, ForecastSource>, 2: array<string, string>}
     */
    private function prepareSeries(array $pairs, int $horizonDays): array
    {
        $series = MlServiceClientFacade::buildSeries($pairs);
        $sourceByPair = [];
        $algorithmByPair = [];

        // Loaded once for the whole run rather than per pair: promotion
        // windows and SKU reference data are the same for every warehouse, and
        // a run covers every stocked pair.
        $promotionWindows = MlServiceClientFacade::promotionWindows();
        $skuMetadata = MlServiceClientFacade::skuMetadata(array_column($series, 'sku_id'));

        foreach ($series as &$entry) {
            $key = $entry['warehouse_id'].'-'.$entry['sku_id'];
            $maturity = ForecastMaturityFacade::classify($entry['sku_id'])['maturity'];
            $algorithm = ModelSelectionFacade::chooseAlgorithm($entry['sku_id']);

            if ($maturity === ForecastMaturity::ColdStart) {
                $fallback = DemandProfileFacade::fallbackSeries($entry['warehouse_id'], $entry['sku_id'], MlServiceClient::HISTORY_DAYS);
                $entry['daily_sold_qty'] = $fallback['daily_sold_qty'];
                $sourceByPair[$key] = $fallback['source'];
                // A substituted series is peer demand, not this SKU's. A
                // trained model would read it under this SKU's own learned
                // embedding, price and category — describing one product with
                // another's sales. Baselines have no such notion of identity,
                // so they are the honest choice here.
                $algorithm = $this->baselineFor($algorithm);
                $entry['algorithm'] = $algorithm;
                $algorithmByPair[$key] = $algorithm;

                continue;
            }

            if ($maturity === ForecastMaturity::Early) {
                $fallback = DemandProfileFacade::fallbackSeries($entry['warehouse_id'], $entry['sku_id'], MlServiceClient::HISTORY_DAYS);
                $entry['daily_sold_qty'] = $this->blend($entry['daily_sold_qty'], $fallback['daily_sold_qty']);
                $sourceByPair[$key] = ForecastSource::Hybrid;
                $algorithm = $this->baselineFor($algorithm);
                $entry['algorithm'] = $algorithm;
                $algorithmByPair[$key] = $algorithm;

                continue;
            }

            $sourceByPair[$key] = ForecastSource::SkuHistory;

            if (in_array($algorithm, MlServiceClient::NEURAL_ALGORITHMS, true)) {
                $features = MlServiceClientFacade::buildNeuralFeatures(
                    ['warehouse_id' => $entry['warehouse_id'], 'sku_id' => $entry['sku_id']],
                    $promotionWindows,
                    $skuMetadata[$entry['sku_id']] ?? null,
                    $horizonDays,
                );

                // No snapshot history means no covariates to send. Rather than
                // ship a half-filled block, drop back to a baseline here —
                // Python would refuse it anyway, and refusing locally saves the
                // round trip and keeps the reason on this side of the wire.
                if ($features === null) {
                    $algorithm = $this->baselineFor($algorithm);
                } else {
                    $entry['features'] = $features;
                }
            }

            $entry['algorithm'] = $algorithm;
            $algorithmByPair[$key] = $algorithm;
        }

        unset($entry);

        return [$series, $sourceByPair, $algorithmByPair];
    }

    /**
     * The algorithm to use when a neural one was selected but cannot be served
     * for this pair. A non-neural selection passes through unchanged.
     */
    private function baselineFor(string $algorithm): string
    {
        return in_array($algorithm, MlServiceClient::NEURAL_ALGORITHMS, true)
            ? ModelSelectionService::DEFAULT_ALGORITHM
            : $algorithm;
    }

    /**
     * @param  list<int|float>  $ownSeries
     * @param  list<float>  $fallbackSeries
     * @return list<float>
     */
    private function blend(array $ownSeries, array $fallbackSeries): array
    {
        $length = count($fallbackSeries);
        $blended = [];

        for ($i = 0; $i < $length; $i++) {
            $own = (float) ($ownSeries[$i] ?? 0);
            $fallback = $fallbackSeries[$i];
            $blended[] = round((self::EARLY_SKU_WEIGHT * $own) + ((1 - self::EARLY_SKU_WEIGHT) * $fallback), 2);
        }

        return $blended;
    }

    /**
     * Which warehouse/SKU pairs a run covers.
     *
     * On the ledger source that is every pair with a stock balance — this
     * application holds the stock, so it knows the full population. On the
     * BuyAbans source it is every pair the synced demand actually resolved to a
     * local SKU and warehouse: this application holds no stock of its own, so a
     * balance row is not the population, and a pair with no demand history is
     * nothing to forecast from.
     *
     * @return array<int, array{warehouse_id: int, sku_id: int}>
     */
    private function pairsInScope(?array $warehouseIds): array
    {
        $query = MlTrainingDataService::demandSource() === MlTrainingDataService::SOURCE_BUYABANS
            ? DB::table('buyabans_daily_demands')
                ->where('grain', config('services.buyabans.grain', 'warehouse'))
                ->whereNotNull('warehouse_id')
                ->whereNotNull('sku_id')
            : DB::table('inventories');

        return $query
            ->when($warehouseIds, fn ($builder, $ids) => $builder->whereIn('warehouse_id', $ids))
            ->select(['warehouse_id', 'sku_id'])
            ->distinct()
            ->get()
            ->map(fn ($row) => ['warehouse_id' => (int) $row->warehouse_id, 'sku_id' => (int) $row->sku_id])
            ->all();
    }

    /**
     * A one-line account of what the model declined, for the run row.
     *
     * **Refused pairs get no forecast at all.** The service used to answer a
     * refusal with EWMA, so a run always came back full; asking for a trained
     * model and receiving arithmetic under its own name was accurate
     * bookkeeping but a surprise, and it hid the refusal behind a number. The
     * pairs are simply absent now, and this says how many and why so the
     * absence is visible and can be retried.
     *
     * @param  list<array<string, mixed>>  $refusals
     */
    private function refusalMessage(array $refusals): ?string
    {
        if ($refusals === []) {
            return null;
        }

        $algorithm = (string) ($refusals[0]['algorithm'] ?? 'the model');
        $reasons = array_slice(array_values(array_unique(
            array_filter(array_column($refusals, 'reason'))
        )), 0, 3);

        return sprintf(
            '%s declined %d %s and no forecast was produced for %s. %s',
            $algorithm,
            count($refusals),
            count($refusals) === 1 ? 'series' : 'series',
            count($refusals) === 1 ? 'it' : 'them',
            $reasons === [] ? 'No reason was given.' : implode(' ', array_map(
                fn (string $reason): string => rtrim($reason, '.').'.',
                $reasons,
            )),
        );
    }
}
