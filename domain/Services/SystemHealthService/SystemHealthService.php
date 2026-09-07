<?php

declare(strict_types=1);

namespace Domain\Services\SystemHealthService;

use Carbon\CarbonImmutable;
use Domain\Services\DashboardService\DashboardService;
use Domain\Services\ModelSelectionService\ModelSelectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What this system's moving parts are doing right now.
 *
 * Computed on request and never persisted — the same "report, not a CRUD
 * module" shape as {@see DashboardService}. Owns no table and takes no model.
 *
 * **Read-only, including the probes.** The ML service and the BuyAbans API are
 * contacted with GETs on short timeouts. A health page that cannot reach a
 * dependency has to say so rather than fail, so every probe is wrapped and
 * returns a reachable/unreachable verdict instead of throwing.
 *
 * **Nothing here is invented.** Where a figure cannot be produced — no training
 * artefact on disk, a driver that cannot report table sizes — the section is
 * absent and the page says why. A health page that shows a green tick it did
 * not verify is worse than one that admits it did not look.
 */
final class SystemHealthService
{
    /**
     * Seconds to wait on a dependency probe before calling it unreachable.
     *
     * Eight, not five. The back office answers an unauthenticated request in
     * ~2.2s cold, and a five-second budget turned an ordinary slow response
     * into a red "unreachable" — a health page that cries wolf gets ignored,
     * which is worse than one that waits a moment longer. Latency is reported
     * alongside the verdict so "up but slow" stays visible as itself.
     */
    private const PROBE_TIMEOUT = 8;

    /**
     * Days of history the cross-grain reconciliation compares over.
     *
     * The check asks whether the grains agree, and they agree over any window
     * or none — so it does not need all four years. Over the full history it
     * cost ~10s per grain: `SUM(sold_qty)` appears in no index, so every row
     * gets read. Bounded to a recent window it rides `(grain, demand_date)`.
     */
    private const RECONCILE_DAYS = 90;

    /** Sync runs are considered stale beyond this. */
    private const SYNC_STALE_HOURS = 36;

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'generatedAt' => now()->format('d M Y, H:i'),
            'runtime' => $this->runtime(),
            'dependencies' => $this->dependencies(),
            'models' => $this->models(),
            'training' => $this->training(),
            'evaluation' => $this->evaluation(),
            'data' => $this->dataFreshness(),
            'syncRuns' => $this->syncRuns(),
            'pipeline' => $this->pipeline(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
        ];
    }

    /**
     * The interpreter and framework actually running, not what is pinned.
     *
     * @return array<string, string|bool>
     */
    private function runtime(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => (string) app()->environment(),
            'debug' => (bool) config('app.debug'),
            'timezone' => (string) config('app.timezone'),
            'demandSource' => (string) config('services.ml.demand_source', 'ledger'),
            'grain' => (string) config('services.buyabans.grain', 'warehouse'),
            'queueDriver' => (string) config('queue.default'),
            'cacheDriver' => (string) config('cache.default'),
            'sessionDriver' => (string) config('session.driver'),
        ];
    }

    /**
     * Can this application reach the things it depends on?
     *
     * @return list<array{name: string, reachable: bool, detail: string, latencyMs: int|null, configured: bool}>
     */
    private function dependencies(): array
    {
        return [
            $this->probeDatabase(),
            $this->probeMlService(),
            $this->probeBuyabans(),
        ];
    }

    /**
     * @return array{name: string, reachable: bool, detail: string, latencyMs: int|null, configured: bool}
     */
    private function probeDatabase(): array
    {
        $started = microtime(true);

        try {
            DB::connection()->getPdo();
            $driver = (string) DB::connection()->getDriverName();
            $version = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return [
                'name' => 'Database',
                'reachable' => true,
                'detail' => $driver.' '.$version,
                'latencyMs' => (int) round((microtime(true) - $started) * 1000),
                'configured' => true,
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'Database',
                'reachable' => false,
                'detail' => $this->trim($e->getMessage()),
                'latencyMs' => null,
                'configured' => true,
            ];
        }
    }

    /**
     * @return array{name: string, reachable: bool, detail: string, latencyMs: int|null, configured: bool}
     */
    private function probeMlService(): array
    {
        $url = rtrim((string) config('services.ml.url', ''), '/');

        if ($url === '') {
            return [
                'name' => 'ML service',
                'reachable' => false,
                'detail' => 'No URL configured (services.ml.url)',
                'latencyMs' => null,
                'configured' => false,
            ];
        }

        $started = microtime(true);

        try {
            $response = Http::baseUrl($url)->timeout(self::PROBE_TIMEOUT)->acceptJson()->get('/models');
            $latency = (int) round((microtime(true) - $started) * 1000);

            return [
                'name' => 'ML service',
                'reachable' => $response->successful(),
                'detail' => $response->successful()
                    ? $url
                    : $url.' returned HTTP '.$response->status(),
                'latencyMs' => $latency,
                'configured' => true,
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'ML service',
                'reachable' => false,
                'detail' => $this->trim($e->getMessage()),
                'latencyMs' => null,
                'configured' => true,
            ];
        }
    }

    /**
     * The back office is probed **unauthenticated** and on purpose.
     *
     * This asks one question — is the host answering? — and a token request
     * would answer a different one at the cost of minting a token on every page
     * view. A 401 is therefore a *pass*: it proves something is listening and
     * enforcing auth.
     *
     * @return array{name: string, reachable: bool, detail: string, latencyMs: int|null, configured: bool}
     */
    private function probeBuyabans(): array
    {
        $url = rtrim((string) config('services.buyabans.url', ''), '/');
        $configured = $url !== ''
            && (string) config('services.buyabans.client_id', '') !== ''
            && (string) config('services.buyabans.client_secret', '') !== '';

        if ($url === '') {
            return [
                'name' => 'BuyAbans API',
                'reachable' => false,
                'detail' => 'No URL configured (services.buyabans.url)',
                'latencyMs' => null,
                'configured' => false,
            ];
        }

        $started = microtime(true);

        try {
            $response = Http::baseUrl($url)->timeout(self::PROBE_TIMEOUT)->acceptJson()
                ->get('/api/forecasting/locations');
            $latency = (int) round((microtime(true) - $started) * 1000);

            return [
                'name' => 'BuyAbans API',
                'reachable' => $response->status() > 0,
                'detail' => $configured
                    ? $url.' answering (HTTP '.$response->status().')'
                    : $url.' answering, but no client credentials are set',
                'latencyMs' => $latency,
                'configured' => $configured,
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'BuyAbans API',
                'reachable' => false,
                'detail' => $this->trim($e->getMessage()),
                'latencyMs' => null,
                'configured' => $configured,
            ];
        }
    }

    /**
     * What the ML service is prepared to serve, and which of it this
     * application will actually use.
     *
     * **`trained_through` is the figure to read.** A checkpoint trained on an
     * older catalogue does not degrade gracefully — its categorical embeddings
     * were sized for the id space it saw, so a later id crashes the forward
     * pass outright. `/models` reports such a checkpoint as `available: true`,
     * which is why the staleness is computed here and shown next to it rather
     * than left for someone to notice.
     *
     * @return array{reachable: bool, message: string|null, preferred: string, algorithms: list<array<string, mixed>>}
     */
    private function models(): array
    {
        $preferred = app(ModelSelectionService::class)->preferredAlgorithm();
        $lastDemandDay = $this->latestDemandDate();

        try {
            $url = rtrim((string) config('services.ml.url', ''), '/');

            if ($url === '') {
                return ['reachable' => false, 'message' => 'No ML service URL configured', 'preferred' => $preferred, 'algorithms' => []];
            }

            $response = Http::baseUrl($url)->timeout(self::PROBE_TIMEOUT)->acceptJson()->get('/models');

            if (! $response->successful()) {
                return ['reachable' => false, 'message' => 'ML service returned HTTP '.$response->status(), 'preferred' => $preferred, 'algorithms' => []];
            }

            $algorithms = [];

            foreach ((array) $response->json('algorithms', []) as $name => $details) {
                $trainedThrough = $details['trained_through'] ?? null;
                $staleDays = null;

                if ($trainedThrough !== null && $lastDemandDay !== null) {
                    $staleDays = (int) CarbonImmutable::parse((string) $trainedThrough)
                        ->diffInDays($lastDemandDay, false);
                }

                $algorithms[] = [
                    'name' => (string) $name,
                    'trained' => (bool) ($details['trained'] ?? false),
                    'available' => (bool) ($details['available'] ?? false),
                    'trainedThrough' => $trainedThrough === null ? null : (string) $trainedThrough,
                    'maxHorizonDays' => isset($details['max_horizon_days']) ? (int) $details['max_horizon_days'] : null,
                    'minHistoryDays' => isset($details['min_history_days']) ? (int) $details['min_history_days'] : null,
                    'behindDemandDays' => $staleDays,
                    'isPreferred' => (string) $name === $preferred,
                ];
            }

            return ['reachable' => true, 'message' => null, 'preferred' => $preferred, 'algorithms' => $algorithms];
        } catch (Throwable $e) {
            return ['reachable' => false, 'message' => $this->trim($e->getMessage()), 'preferred' => $preferred, 'algorithms' => []];
        }
    }

    /**
     * The last training run's own scores, read from the artefact the Python
     * pipeline writes.
     *
     * @return array<string, mixed>|null
     */
    private function training(): ?array
    {
        $summary = $this->readJson(base_path('ml-service/models/training_summary.json'));

        if ($summary === null) {
            return null;
        }

        $models = [];

        foreach ((array) ($summary['models'] ?? []) as $model) {
            $scores = (array) ($model['scores'] ?? []);

            $models[] = [
                'name' => (string) ($model['name'] ?? '?'),
                'wape' => isset($scores['wape']) ? round((float) $scores['wape'], 4) : null,
                'mae' => isset($scores['mae']) ? round((float) $scores['mae'], 4) : null,
                'bias' => isset($scores['bias']) ? round((float) $scores['bias'], 4) : null,
                'actualTotal' => isset($scores['actual_total']) ? round((float) $scores['actual_total']) : null,
                'predictedTotal' => isset($scores['predicted_total']) ? round((float) $scores['predicted_total']) : null,
            ];
        }

        $config = (array) ($summary['config'] ?? []);

        return [
            'holdoutDays' => isset($config['holdout_days']) ? (int) $config['holdout_days'] : null,
            'maxEpochs' => isset($config['max_epochs']) ? (int) $config['max_epochs'] : null,
            'tftLoss' => isset($config['tft_loss']) ? (string) $config['tft_loss'] : null,
            'writtenAt' => $this->fileTime(base_path('ml-service/models/training_summary.json')),
            'models' => $models,
        ];
    }

    /**
     * The last backtest — the only comparison that says anything about which
     * algorithm to serve, because it scores every one of them on the same
     * held-out windows.
     *
     * @return array<string, mixed>|null
     */
    private function evaluation(): ?array
    {
        $report = $this->readJson(base_path('ml-service/models/evaluation.json'));

        if ($report === null) {
            return null;
        }

        $pooled = [];

        foreach ((array) ($report['pooled_horizon_total'] ?? []) as $name => $scores) {
            $pooled[] = [
                'name' => (string) $name,
                'wape' => isset($scores['wape']) ? round((float) $scores['wape'], 4) : null,
                'mae' => isset($scores['mae']) ? round((float) $scores['mae'], 4) : null,
                'bias' => isset($scores['bias']) ? round((float) $scores['bias'], 4) : null,
            ];
        }

        usort($pooled, fn (array $a, array $b) => ($a['wape'] ?? INF) <=> ($b['wape'] ?? INF));

        return [
            'horizonDays' => isset($report['horizon_days']) ? (int) $report['horizon_days'] : null,
            'windows' => count((array) ($report['windows'] ?? [])),
            'best' => isset($report['best']) ? (string) (is_array($report['best']) ? ($report['best']['overall'] ?? '?') : $report['best']) : null,
            'writtenAt' => $this->fileTime(base_path('ml-service/models/evaluation.json')),
            'pooled' => $pooled,
        ];
    }

    /**
     * How current the demand mirror is, per grain.
     *
     * The three grains describe the same sales from different angles, so their
     * unit totals must agree exactly. A row where they do not is a genuine
     * fault — a grain filter forgotten somewhere, or rows dropped at a month
     * boundary — not a rounding difference, which is why the totals are shown
     * side by side rather than summed.
     *
     * Agreement is checked over the trailing {@see self::RECONCILE_DAYS} days
     * rather than all of history, and the page labels the column accordingly.
     * The property either holds or it does not; scanning four years to
     * establish it costs ~30s and proves nothing extra.
     *
     * @return array{grains: list<array<string, mixed>>, unitsAgree: bool}
     */
    private function dataFreshness(): array
    {
        $grains = [];
        $totals = [];
        $since = now()->subDays(self::RECONCILE_DAYS)->toDateString();

        foreach (['warehouse', 'channel', 'national'] as $grain) {
            // Three queries, not one. `COUNT(*)` combined with `MIN`/`MAX` in a
            // single SELECT costs MySQL the index min/max optimisation and
            // degenerates into a full scan of the grain: measured 15.31s
            // combined against 0.778s split (0.776 + 0.0008 + 0.0012), because
            // the two extremes are index seeks the moment they stand alone.
            $rows = (int) DB::table('buyabans_daily_demands')->where('grain', $grain)->count();
            $firstDay = DB::table('buyabans_daily_demands')->where('grain', $grain)->min('demand_date');
            $lastDay = DB::table('buyabans_daily_demands')->where('grain', $grain)->max('demand_date');

            if ($rows === 0) {
                $grains[] = ['grain' => $grain, 'rows' => 0, 'skus' => 0, 'firstDay' => null, 'lastDay' => null, 'unitsInWindow' => 0.0, 'daysBehind' => null];

                continue;
            }

            $units = round((float) DB::table('buyabans_daily_demands')
                ->where('grain', $grain)
                ->where('demand_date', '>=', $since)
                ->sum('sold_qty'));
            $totals[] = $units;

            $grains[] = [
                'grain' => $grain,
                'rows' => $rows,
                'skus' => $this->countDistinctSkus($grain),
                'firstDay' => $firstDay === null ? null : CarbonImmutable::parse((string) $firstDay)->format('d M Y'),
                'lastDay' => $lastDay === null ? null : CarbonImmutable::parse((string) $lastDay)->format('d M Y'),
                'unitsInWindow' => $units,
                'daysBehind' => $lastDay === null
                    ? null
                    : (int) CarbonImmutable::parse((string) $lastDay)->startOfDay()->diffInDays(now()->startOfDay(), false),
            ];
        }

        return [
            'grains' => $grains,
            'reconcileDays' => self::RECONCILE_DAYS,
            'unitsAgree' => count(array_unique($totals)) <= 1,
        ];
    }

    /**
     * Counted through the covering index rather than `COUNT(DISTINCT ...)`,
     * for the reason on {@see DashboardService::countDistinctSkus()}.
     */
    private function countDistinctSkus(string $grain): int
    {
        return (int) DB::query()
            ->fromSub(
                DB::table('buyabans_daily_demands')->where('grain', $grain)->select('sku_id')->distinct(),
                'tracked'
            )
            ->count();
    }

    /**
     * The most recent run of each sync stage, and whether it is overdue.
     *
     * @return list<array<string, mixed>>
     */
    private function syncRuns(): array
    {
        $latestIds = DB::table('buyabans_sync_runs')
            ->selectRaw('MAX(id) AS id')
            ->groupBy('stage')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return [];
        }

        return DB::table('buyabans_sync_runs')
            ->whereIn('id', $latestIds)
            ->orderBy('stage')
            ->get()
            ->map(function ($run) {
                $finished = $run->finished_at === null ? null : CarbonImmutable::parse((string) $run->finished_at);

                return [
                    'stage' => (string) $run->stage,
                    'status' => (string) $run->status,
                    'grain' => $run->grain === null ? null : (string) $run->grain,
                    'fetched' => (int) ($run->records_fetched ?? 0),
                    'written' => (int) ($run->records_written ?? 0),
                    'finishedAt' => $finished?->format('d M Y, H:i'),
                    'hoursAgo' => $finished === null ? null : (int) $finished->diffInHours(now()),
                    'stale' => $finished !== null && $finished->diffInHours(now()) > self::SYNC_STALE_HOURS,
                    'message' => $run->message === null ? null : $this->trim((string) $run->message),
                ];
            })
            ->all();
    }

    /**
     * Forecast runs and what came out of them.
     *
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        $latest = DB::table('ml_forecast_runs')->orderByDesc('id')->first();

        $byStatus = DB::table('ml_forecast_runs')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $durationSeconds = null;

        if ($latest !== null && $latest->started_at !== null && $latest->finished_at !== null) {
            $durationSeconds = (int) CarbonImmutable::parse((string) $latest->started_at)
                ->diffInSeconds(CarbonImmutable::parse((string) $latest->finished_at));
        }

        return [
            'latestRun' => $latest === null ? null : [
                'id' => (int) $latest->id,
                'status' => (string) $latest->status,
                'horizonDays' => (int) $latest->horizon_days,
                'forecasts' => DB::table('forecasts')->where('forecast_run_id', $latest->id)->count(),
                'finishedAt' => $latest->finished_at === null ? null : CarbonImmutable::parse((string) $latest->finished_at)->format('d M Y, H:i'),
                'durationSeconds' => $durationSeconds,
                'error' => $latest->error_message === null ? null : $this->trim((string) $latest->error_message),
            ],
            'runsByStatus' => $byStatus,
            'scoredForecasts' => DB::table('forecast_accuracy')->count(),
            'openRecommendations' => DB::table('inventory_recommendations')->whereIn('status', ['NEW', 'PENDING'])->count(),
        ];
    }

    /**
     * Queue depth, and the age of the oldest waiting job.
     *
     * **The age is the useful number.** A forecast batch that outgrows its
     * worker timeout is killed without failing — nothing reaches `failed_jobs`
     * and the run sits at `processing` forever — so a job that has been waiting
     * far longer than a run takes is the only visible symptom.
     *
     * @return array{driver: string, pending: int, failed: int, oldestPendingMinutes: int|null}
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');

        if ($driver !== 'database') {
            return ['driver' => $driver, 'pending' => 0, 'failed' => 0, 'oldestPendingMinutes' => null];
        }

        $oldest = DB::table('jobs')->min('created_at');

        return [
            'driver' => $driver,
            'pending' => (int) DB::table('jobs')->count(),
            'failed' => (int) DB::table('failed_jobs')->count(),
            'oldestPendingMinutes' => $oldest === null
                ? null
                : (int) CarbonImmutable::createFromTimestamp((int) $oldest)->diffInMinutes(now()),
        ];
    }

    /**
     * The tables that actually carry weight.
     *
     * MySQL only — sizes come from `information_schema`, which SQLite does not
     * have. On any other driver this returns null and the page says the driver
     * cannot report sizes, rather than showing zeroes that look like an empty
     * database.
     *
     * @return array{driver: string, tables: list<array{name: string, rows: int, megabytes: float}>}|null
     */
    private function storage(): ?array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        try {
            $rows = DB::select(
                'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate,
                        ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 1) AS megabytes
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                  ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
                  LIMIT 8'
            );

            return [
                'driver' => 'mysql',
                'tables' => array_map(fn ($row) => [
                    'name' => (string) $row->name,
                    'rows' => (int) ($row->row_estimate ?? 0),
                    'megabytes' => (float) ($row->megabytes ?? 0),
                ], $rows),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function latestDemandDate(): ?CarbonImmutable
    {
        $last = DB::table('buyabans_daily_demands')
            ->where('grain', (string) config('services.buyabans.grain', 'warehouse'))
            ->max('demand_date');

        return $last === null ? null : CarbonImmutable::parse((string) $last);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! File::exists($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fileTime(string $path): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp(File::lastModified($path))->format('d M Y, H:i');
    }

    /** Exception text belongs on a page in one line, not as a stack trace. */
    private function trim(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 160 ? mb_substr($message, 0, 159).'…' : $message;
    }
}
