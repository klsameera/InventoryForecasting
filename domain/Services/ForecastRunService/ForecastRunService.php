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

            $results = MlServiceClientFacade::send($series, $run->horizon_days);

            $algorithmTally = [];

            foreach ($results as $result) {
                $key = $result['warehouse_id'].'-'.$result['sku_id'];

                // The algorithm the service actually RAN, which is not always
                // the one requested: a trained model refuses a series it cannot
                // honestly serve (unknown SKU, too little history, horizon
                // beyond its decoder) and the service answers with a baseline,
                // echoing what it really used. Stamping the requested algorithm
                // instead would file a baseline number under the neural model's
                // name and poison exactly the accuracy history
                // ModelSelectionService reads to choose next time.
                $algorithm = $result['algorithm']
                    ?? $algorithmByPair[$key]
                    ?? ModelSelectionService::DEFAULT_ALGORITHM;

                if (! empty($result['fallback_reason'])) {
                    Log::info('ML service fell back to a baseline', [
                        'run_id' => $runId,
                        'warehouse_id' => $result['warehouse_id'],
                        'sku_id' => $result['sku_id'],
                        'requested' => $algorithmByPair[$key] ?? null,
                        'used' => $algorithm,
                        'reason' => $result['fallback_reason'],
                    ]);
                }

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

            $run->update([
                'status' => ForecastRunStatus::Completed,
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
}
