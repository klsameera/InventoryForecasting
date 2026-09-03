<?php

declare(strict_types=1);

namespace Domain\Services\ModelSelectionService;

use App\Models\ForecastAccuracy;
use App\Models\MlModelVersion;
use Domain\Services\DemandProfileService\DemandProfileService;
use Domain\Services\ForecastMaturityService\ForecastMaturityService;
use Domain\Services\ForecastRunService\ForecastRunService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — "automatic model selection" (app_plan.md §86). The Python
 * `ml-service` implements four algorithms: two statistical baselines
 * (`app/forecasting/baseline.py`'s EWMA and
 * `app/forecasting/seasonal_naive.py`'s day-of-week averaging) and two
 * trained neural checkpoints (`app/forecasting/neural.py`'s TFT and DeepAR).
 * This Service is what actually *selects* between them, per SKU, from real
 * backtested {@see ForecastAccuracy} history. Python itself has
 * no opinion; it only dispatches on whichever `algorithm` a series requests
 * (see `ml-service/app/schemas.py`'s `SeriesInput.algorithm`), and refuses —
 * with a baseline and a stated reason — anything it cannot honestly serve.
 *
 * No migration, no model of its own, nothing persisted beyond the
 * {@see MlModelVersion} rows both algorithms already need to exist —
 * infrastructure, the same "computed, no model" shape as
 * {@see ForecastMaturityService}
 * and {@see DemandProfileService}.
 */
final class ModelSelectionService
{
    /**
     * A SKU with fewer than two algorithms' worth of comparable accuracy
     * history falls back here — this is not a claim that EWMA is "better,"
     * only that there is nothing yet to compare it against. Never guesses.
     */
    public const DEFAULT_ALGORITHM = 'ewma';

    /**
     * @var array<string, array{name: string, version: string, model_type: string}>
     */
    private const CANDIDATE_ALGORITHMS = [
        'ewma' => ['name' => 'baseline-moving-average', 'version' => 'v1', 'model_type' => 'moving_average'],
        'seasonal_naive' => ['name' => 'seasonal-naive', 'version' => 'v1', 'model_type' => 'seasonal_naive'],
        'tft' => ['name' => 'temporal-fusion-transformer', 'version' => 'v1', 'model_type' => 'tft'],
        'deepar' => ['name' => 'deepar', 'version' => 'v1', 'model_type' => 'deepar'],
    ];

    /**
     * The algorithm to use for an Established SKU that has no scored accuracy
     * history to choose from yet — configurable because it is a real
     * operational decision, not a code-level one.
     *
     * **Why this exists.** The comparison below cannot bootstrap itself: a
     * forecast is only scored once its horizon has elapsed, so on a fresh
     * install (`forecast_accuracy` empty) every SKU falls through to a
     * default, and whatever that default is, is what actually runs — possibly
     * for months. Hardcoding {@see DEFAULT_ALGORITHM} there quietly meant "the
     * trained model is never used, regardless of whether one exists".
     *
     * Set `ML_DEFAULT_ALGORITHM=tft` to serve the trained model while accuracy
     * history accumulates; it is overridden per SKU as soon as a real
     * comparison becomes possible. Unset, this stays {@see DEFAULT_ALGORITHM}
     * and behaviour is exactly as before.
     *
     * Deliberately **not** checked against what the ML service can currently
     * serve. That probe was written and removed: it made every forecast run
     * depend on an extra HTTP call, and it bought nothing, because the service
     * already refuses a model it cannot run and answers with a baseline —
     * echoing the algorithm it really used, which is what
     * {@see ForecastRunService::processRun()} records. A misconfiguration here
     * is visible in the data rather than hidden by a pre-flight check.
     */
    public function preferredAlgorithm(): string
    {
        $configured = (string) config('services.ml.default_algorithm', self::DEFAULT_ALGORITHM);

        if (! array_key_exists($configured, self::CANDIDATE_ALGORITHMS)) {
            return self::DEFAULT_ALGORITHM;
        }

        return $configured;
    }

    /**
     * Whether an algorithm is one this Service knows how to register and rank.
     */
    public function isCandidate(string $algorithm): bool
    {
        return array_key_exists($algorithm, self::CANDIDATE_ALGORITHMS);
    }

    /**
     * Picks whichever candidate algorithm has scored the lower average
     * {@see ForecastAccuracy::$percentage_error} for this SKU
     * across every forecast it has ever produced and had scored.
     *
     * Requires **at least two** algorithms to have scored history before it
     * will rank them — comparing "the only algorithm that's ever run" against
     * nothing isn't a real comparison. Below that it returns
     * {@see preferredAlgorithm()}.
     *
     * Note this compares only the algorithms that *have* history, rather than
     * requiring every candidate to have some. With two candidates those were
     * the same rule; with four they are not, and demanding all four would mean
     * the comparison never runs at all.
     */
    public function chooseAlgorithm(int $skuId): string
    {
        $averageErrorByModelName = DB::table('forecast_accuracy')
            ->join('forecasts', 'forecasts.id', '=', 'forecast_accuracy.forecast_id')
            ->join('ml_model_versions', 'ml_model_versions.id', '=', 'forecasts.model_version_id')
            ->where('forecasts.sku_id', $skuId)
            ->whereNotNull('forecast_accuracy.percentage_error')
            ->select('ml_model_versions.name', DB::raw('AVG(forecast_accuracy.percentage_error) as avg_error'))
            ->groupBy('ml_model_versions.name')
            ->pluck('avg_error', 'name');

        $errorsByAlgorithm = [];

        foreach (self::CANDIDATE_ALGORITHMS as $algorithm => $spec) {
            if (isset($averageErrorByModelName[$spec['name']])) {
                $errorsByAlgorithm[$algorithm] = (float) $averageErrorByModelName[$spec['name']];
            }
        }

        if (count($errorsByAlgorithm) < 2) {
            return $this->preferredAlgorithm();
        }

        asort($errorsByAlgorithm);

        return (string) array_key_first($errorsByAlgorithm);
    }

    /**
     * Finds-or-registers the {@see MlModelVersion} row for a given algorithm.
     *
     * Each candidate registers one fixed row. For the two baselines that is
     * because they are not "trained" in the ML sense and have nothing to
     * version — the reasoning {@see MlModelVersion}'s own docblock records for
     * the original EWMA row. For `tft` and `deepar` it is a **known
     * simplification**: retraining overwrites `best.ckpt` in place, so every
     * retrained model reuses version `v1` and forecasts made by an older
     * checkpoint are indistinguishable from newer ones in the data. Given the
     * models decay with age (docs/app_architecture.md §1p), a real deployment
     * wants a version per training run; that needs a checkpoint identifier
     * written by `training/train.py` and read back here, and is deliberately
     * not built yet.
     */
    public function modelVersionFor(string $algorithm): MlModelVersion
    {
        $spec = self::CANDIDATE_ALGORITHMS[$algorithm] ?? self::CANDIDATE_ALGORITHMS[self::DEFAULT_ALGORITHM];

        return MlModelVersion::query()->firstOrCreate(
            ['name' => $spec['name'], 'version' => $spec['version']],
            ['model_type' => $spec['model_type'], 'feature_schema_version' => 'v1', 'status' => 'active'],
        );
    }
}
