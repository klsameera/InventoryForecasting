# Server architecture — InventoryForecasting

Infrastructure, deployment, backing services, environment variables, queues and
scheduled work.

Companion documents: [`app_guide.md`](app_guide.md),
[`app_architecture.md`](app_architecture.md).

> **Scope note.** This project has **no production deployment yet.** There is no
> Dockerfile, no compose file, no Procfile, no deploy script and no
> infrastructure-as-code in the repository. What follows documents the local
> development environment, the CI pipeline, and the service configuration that
> exists — plus an explicit list of what must be decided before a first deploy.
> Nothing here is invented.

---

## 1. Runtime requirements

| Component | Version | Notes |
| --- | --- | --- |
| PHP | **^8.3** | `composer.json` requires `^8.3`; `ext-sockets` is **not** enabled |
| Node.js | 22 in CI, 20.20.0 locally | CI pins 22 |
| Composer | v2 | |
| Web server | any PHP-capable | `public/` is the document root |

### The PHP version trap

`php` on this machine's `PATH` resolves to **8.2**, below the app's floor.
Artisan and Composer fail their platform check. Prepend Laragon's 8.3 build in
any shell that touches them:

```bash
export PATH="/c/laragon/bin/php/php-8.3.33-Win32-vs16-x64:$PATH"
```

This applies to the `laravel-boost` MCP server too — it runs `php artisan
boost:mcp` and inherits whatever `php` the session resolves.

---

## 2. Local development

Local host: **Laragon** on Windows, serving from `c:\laragon\www\InventoryForecasting`.

First-time setup (`composer setup`) runs: `composer install` → copy
`.env.example` to `.env` if absent → `key:generate` → `migrate --force` →
`npm install` → `npm run build`.

Day-to-day (`composer dev`) runs three processes concurrently under
`concurrently`:

| Process | Command |
| --- | --- |
| server | `php artisan serve` |
| queue | `php artisan queue:listen --tries=1` |
| vite | `npm run dev` |

App URL: **http://localhost:8000** (`APP_URL`).

`laravel/sail` is installed as a dev dependency and `vendor/bin/sail` exists,
but **no `docker-compose.yml` is published**, so Sail is not currently usable
without running `sail:install` first.

**`composer dev` does not start the Python ML service** — it's a separate
runtime outside Laravel's process tree. See §8.

---

## 3. Backing services

Current configuration, from the active local `.env`:

| Service | Driver | Backing store |
| --- | --- | --- |
| Database | `mysql` | Local MySQL database `inventory` |
| Sessions | `database` | `sessions` table, 120-minute lifetime |
| Cache | `database` | `cache` + `cache_locks` tables |
| Queue | `database` | `jobs`, `job_batches`, `failed_jobs` tables |
| Mail | `log` | written to `storage/logs/laravel.log` |
| Broadcasting | `log` | |
| Filesystem | `local` | `storage/app` |
| Logging | `stack` → `single` | `LOG_LEVEL=debug` |

**Everything is database-backed, and the active local environment uses
MySQL.** The committed `.env.example` deliberately defaults to SQLite for a
zero-configuration first setup, while `phpunit.xml` uses a fresh in-memory
SQLite database for tests. Sessions, cache and queue therefore use whichever
database connection the active environment selects. Database-backed drivers
are adequate locally; Redis is still the expected production choice for
cache, sessions and queues under concurrent load — see §9.

MySQL, Redis, Memcached and AWS/S3 settings are present in `.env.example` but
commented out or unused. No S3 bucket, Redis instance or external API is
configured. There are **no third-party service credentials** in `config/services.php`
beyond the framework defaults.

---

## 4. Environment variables

| Variable | Local value | Notes |
| --- | --- | --- |
| `APP_NAME` | `Inventory Forecasting` | Product name shown in the shared brand lockup and browser titles; also feeds `VITE_APP_NAME` |
| `APP_ENV` | `local` | Gates production-only behaviour (see below) |
| `APP_KEY` | generated | `php artisan key:generate` |
| `APP_DEBUG` | `true` | **must be `false` in production** |
| `APP_URL` | `http://localhost:8000` | |
| `DB_CONNECTION` | `mysql` | Active local `.env` uses `DB_DATABASE=inventory`; `.env.example` defaults to SQLite and tests force in-memory SQLite |
| `SESSION_DRIVER` | `database` | `SESSION_LIFETIME=120`, `SESSION_ENCRYPT=false` |
| `CACHE_STORE` | `database` | |
| `QUEUE_CONNECTION` | `database` | |
| `MAIL_MAILER` | `log` | Real SMTP needed before email verification works off-box |
| `BCRYPT_ROUNDS` | `12` | |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `debug` | Lower the level in production |
| `ML_SERVICE_URL` | `http://127.0.0.1:8090` | Base URL of the Python ML service (§8); `services.ml.url` |
| `ML_SERVICE_TOKEN` | unset | Optional bearer token sent as `Authorization: Bearer …` when set — must match the Python service's own `ML_SERVICE_API_TOKENS`; `services.ml.token` |
| `ML_SERVICE_TIMEOUT` | `30` | Seconds to wait on `/forecast/run` for a baseline batch; `services.ml.timeout` |
| `ML_SERVICE_NEURAL_TIMEOUT` | `300` | Seconds to wait when any series requests a trained model. Separate from the above because the work differs by an order of magnitude — a 441-pair TFT run takes ~20s warm and the flat 30s that used to apply to both failed the run outright; `services.ml.neural_timeout` |
| `ML_DEFAULT_ALGORITHM` | `ewma` | Which algorithm an Established SKU uses before it has scored accuracy history to rank algorithms from — `ewma`, `seasonal_naive`, `tft` or `deepar`. **This is the switch that decides whether the trained model is served at all**: accuracy history only exists after a forecast's horizon has elapsed and been scored, so until then every SKU takes this value. An unrecognised name falls back to `ewma`; a neural name the service cannot serve is refused per-series by Python and answered with a baseline. `services.ml.default_algorithm` |

### Behaviour that changes with `APP_ENV`

`app/Providers/AppServiceProvider.php` makes two production-only decisions:

1. **`DB::prohibitDestructiveCommands(app()->isProduction())`** — destructive
   migration commands (`migrate:fresh`, `db:wipe`) are blocked in production.
2. **Password strength.** In production: minimum 12 characters, mixed case,
   letters, numbers, symbols, and an uncompromised (HIBP) check. Locally there
   is **no minimum**. Passwords that pass locally will be rejected in
   production — worth knowing before writing a seeder or a test fixture.

Also global: `Date::use(CarbonImmutable::class)`, so date objects are immutable
everywhere.

---

## 5. Queues

Connection `database`, table `jobs`, failures to `failed_jobs`.

Locally the worker is part of `composer dev`
(`php artisan queue:listen --tries=1` — `listen` reloads code between jobs,
which is what you want in development and not what you want in production).

**`App\Jobs\RunDemandForecast`** (Phase 5) is the first job application code
dispatches. `ForecastRunFacade::store()` queues one per forecast run, and it
calls out over HTTP to the Python ML service (§8) — if that service isn't
running, the job's own `processRun()` catches the failure and marks the run
`Failed` with the error recorded, rather than the job itself failing/retrying.
Otherwise, the only queue traffic is whatever Laravel and Fortify queue
internally (notifications, for example email verification, when a queued
notification is used).

**The queue worker is now load-bearing, not incidental** — without it running
(`composer dev`'s `queue:listen`, or a supervised `queue:work` in production),
forecast runs stay `Queued` forever; nothing else processes them.

In production this needs a supervised `php artisan queue:work` (not `listen`)
with restart-on-deploy. Nothing supervises it today.

---

## 6. Scheduled tasks

Four, registered in `routes/console.php`:

| Command | Schedule | Purpose |
| --- | --- | --- |
| `app:capture-inventory-snapshots` | `dailyAt('00:15')`, `withoutOverlapping()` | Captures the previous calendar day's `inventory_daily_snapshots` row for every warehouse/SKU pair — opening/closing balance, demand, stockout minutes. See `app_architecture.md` §1h. |
| `app:score-forecast-accuracy` | `dailyAt('00:30')`, `withoutOverlapping()` | Scores every forecast whose window has elapsed against actual sales from `inventory_daily_snapshots`. Scheduled 15 minutes after the snapshot capture so that day's demand is already recorded. See `app_architecture.md` §1i. |
| `app:generate-inventory-recommendations` | `dailyAt('00:45')`, `withoutOverlapping()` | Re-runs the inventory decision engine (app_plan.md §40, §84, §85) over every warehouse/SKU pair with a forecast — reorder points, dynamic safety stock, purchase quantities, ageing/overstock-driven reduce-purchase/do-not-reorder/clearance recommendations, and cross-warehouse transfer matching. Scheduled last in the nightly sequence. See `app_architecture.md` §1k, §1l, §1m. |
| `app:train-forecast-model` | `monthlyOn(1, '02:00')`, `withoutOverlapping()`, `runInBackground()` | Re-exports the modelling dataset and retrains the neural models. **Not an optimisation — a correctness requirement.** The TFT's advantage over the baselines is entirely conditional on the model being recent (~+8% fresh, negative by four months; `app_architecture.md` §1p), and the baselines cannot decay because they re-derive from the trailing 180 days on every call. A trained model left alone becomes the *worse* choice while still returning confident numbers. Monthly keeps the served checkpoint well inside that window. Runs for hours on CPU, hence `runInBackground()`. |
| `app:capture-supplier-performance` | `monthlyOn(1, '01:00')`, `withoutOverlapping()` | Captures last calendar month's real lead time/fill rate/on-time percentage per supplier from actual purchase-order and goods-receipt dates. Monthly, not nightly — a supplier's lead time needs a real batch of orders to average over. See `app_architecture.md` §1n. |

All four commands also run manually (`php artisan app:capture-inventory-snapshots
{date?}` / `php artisan app:score-forecast-accuracy` /
`php artisan app:generate-inventory-recommendations` /
`php artisan app:capture-supplier-performance {month?}`, or their pages'
buttons) — the schedule always captures/scores/recommends what's already
elapsed or currently true, so nothing is measured or recommended before
it's actually ready.

**This is now required in production**, not optional — add the host cron
entry:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Locally, `composer dev` does not run the scheduler; use `php artisan
schedule:work` in a separate terminal if you need the nightly job to actually
fire during development, or just run the command directly.

---

## 7. CI

`.github/workflows/tests.yml` — on push to `main` and on every pull request.

| Step | Detail |
| --- | --- |
| Runner | `ubuntu-latest` |
| PHP | 8.3, Composer v2, no coverage |
| Node | 22 |
| Setup | `composer setup` |
| Checks | `composer ci:check` |

`composer ci:check` runs, in order: `npm run lint:check` → `npm run
format:check` → `npm run types:check` → `composer test`. `composer test` itself
runs `config:clear`, Pint in `--test` mode, PHPStan (`composer types:check`),
then `php artisan test`.

So CI enforces **both** frontend and backend gates. All actions are pinned to
commit SHAs, and `persist-credentials: false` is set on checkout.

`.github/dependabot.yml` is present for dependency updates.

**Note:** the repository is **not currently a git repository** (`git rev-parse`
fails) despite the `.github/` directory. CI will only run once it is
initialised and pushed to GitHub.

---

## 8. The Python ML service (`ml-service/`)

A separate FastAPI application, not part of the Laravel process tree or
Composer/npm dependency graph — its own Python virtualenv and
`requirements.txt`. It serves **four** algorithms:

| Algorithm | Kind | Needs |
| --- | --- | --- |
| `ewma` | statistical | the daily series only |
| `seasonal_naive` | statistical | the daily series only |
| `tft` | trained checkpoint | the full covariate block, plus `models/tft/` |
| `deepar` | trained checkpoint | the full covariate block, plus `models/deepar/` |

Laravel's `ModelSelectionService` decides which to request per SKU from real
backtested accuracy, falling back to `ML_DEFAULT_ALGORITHM` until there is
enough history to rank; see `app_architecture.md` §1n and §1q.

**A trained model refuses what it cannot honestly serve.** An unknown SKU, a
horizon longer than its 30-day decoder, too little history, or a missing
covariate block all produce a *baseline* answer rather than an error or a
degraded neural one — and the response echoes the algorithm that actually ran,
which is what Laravel stamps on the forecast row. A run therefore never fails
because a checkpoint is absent, and a fallback never accumulates accuracy
history under the neural model's name.

It additionally carries a **training pipeline** (`ml-service/training/`) that
trains DeepAR and a Temporal Fusion Transformer — see `app_architecture.md`
§1p, and note in particular that those models are trained on synthetic seeder
data, so their accuracy figures are not production-representative. This is the
standing reason to treat a served `tft` forecast as a pipeline demonstration
rather than a validated prediction until real sales history exists.

**Python runtime:** 3.10 locally. The dependency set is no longer trivial —
`torch` (CPU build), `pytorch-forecasting`, `pytorch-lightning`, `pandas` and
`numpy` are now serving dependencies too, because the neural algorithms load a
checkpoint and run inference in-process. This materially changes the install
size and the container story for §9. The two statistical baselines still need
none of it.

**Running it locally:**

```bash
cd ml-service
python -m venv .venv
.venv/Scripts/activate   # Windows; .venv/bin/activate on macOS/Linux
pip install -r requirements.txt --extra-index-url https://download.pytorch.org/whl/cpu
cp .env.example .env
uvicorn app.main:app --host 127.0.0.1 --port 8090 --reload
```

**Training the neural models** (separate from serving, and never automatic):

```bash
# 1. Laravel exports the modelling dataset (~414K rows / 20MB at current scale)
php artisan app:export-ml-training-data

# 2. Train (CPU; roughly 3-4 min per epoch at the default batch limit)
cd ml-service
pip install -r requirements-training.txt --extra-index-url https://download.pytorch.org/whl/cpu
.venv/Scripts/python.exe -m training.train

# 3. Score every algorithm on the same held-out window
.venv/Scripts/python.exe -m training.evaluate
```

Checkpoints and metrics land in `ml-service/models/` (`deepar/best.ckpt`,
`tft/best.ckpt`, `training_summary.json`, `evaluation.json`). That directory
is build output, not source — it is regenerated by re-running training and
nothing else reads from it except the serving layer.

**`dataset_params.pt` is written beside each checkpoint and is required to
serve it.** A checkpoint alone is not a servable model: `TimeSeriesDataSet`
carries *fitted* state — the categorical encoders mapping a `sku_id` to an
embedding row, the per-series target normaliser, the continuous scalers — and a
model fed inputs encoded any other way returns plausible nonsense with no
error. The file also records `epoch_date`, because `time_idx` is a known-future
feature the model reads directly (days since the first date of the training
frame), so serving has to resolve calendar dates to the same origin. A
checkpoint without it is reported as unavailable rather than loaded.

**Retraining in production** goes through Laravel so it is scheduled, logged
and repeatable:

```bash
php artisan app:train-forecast-model          # export + retrain, hours on CPU
php artisan app:forecast-model-status         # what is servable, and what is in use
```

`app:forecast-model-status` is the answer to "is the trained model actually
being used?" — three independent things have to line up (service reachable,
checkpoint loadable, `ML_DEFAULT_ALGORITHM` naming it) and a run with all three
misaligned still *succeeds*, quietly returning baseline numbers.

Not started by `composer dev` or any Laravel process — it must be running
separately (its own terminal, or a process manager) for forecast runs to
succeed. If Laravel can't reach it, `RunDemandForecast` marks the run
`Failed` with the HTTP error recorded (§5) — there's no silent hang or crash.

**Contract:** Laravel's `MlServiceClient` (`app_architecture.md` §1i) POSTs a
compact per-SKU daily-sales time series (max 180 days, read from
`inventory_daily_snapshots`) to `{ML_SERVICE_URL}/forecast/run`, never raw
transaction tables. `/health` is a plain liveness check. `daily_sold_qty` is
`list[float]` on the Python side (`schemas.py`), not `list[int]` — a
Cold-start/Early pair's series can be a peer-averaged or own/fallback-blended
value from `DemandProfileService`/`ForecastRunService::blend()`, genuinely
fractional rather than a rounding artifact. This was a real bug (HTTP 422 on
any such pair) found only by running the full pipeline against real seeded
data — `Http::fake()` in `ForecastRunTest.php` had mocked over it.

**Auth:** off by default (empty `ML_SERVICE_API_TOKENS`) — acceptable only
because it listens on `127.0.0.1` and nothing else. Set
`ML_SERVICE_API_TOKENS` (comma-separated) on the Python side and the matching
`ML_SERVICE_TOKEN` in Laravel's `.env` before this ever runs anywhere
network-reachable; `MlServiceClient` already sends
`Authorization: Bearer <token>` whenever `ML_SERVICE_TOKEN` is set.

**Tests:** `pytest` inside the service's own virtualenv
(`ml-service/tests/test_baseline.py`, `test_seasonal_naive.py`, `test_api.py`,
`test_neural.py`) — `test_neural.py` deliberately loads no checkpoint, so the
suite still runs on a fresh clone with no `models/` directory
— not part of `composer ci:check` or the GitHub Actions workflow (§7), since
CI has no Python runtime configured yet. Run it manually when touching
`ml-service/`.

**Not yet decided for production** (see §9): hosting/process supervision for
a second runtime alongside PHP, private networking so the service isn't
publicly reachable (`app_plan.md` §74), and whether it stays a single Python
process or needs its own deploy pipeline. None of this is set up.

---

## 9. Before a first production deploy

Open decisions, none of them made yet. Listed so they are not discovered at
deploy time:

- **Hosting target.** No platform chosen. Laravel Cloud, Forge, a container
  host and plain VPS all remain options; nothing in the repo assumes one.
- **Database.** Local dev already runs against MySQL (`.env`'s
  `DB_CONNECTION=mysql`, a local Laragon instance) — `php artisan test`
  still always uses a fresh in-memory SQLite database regardless
  (`phpunit.xml` overrides the connection for tests), so this was never
  exercised by the test suite. **Before deploying to MySQL, check every
  migration's composite index/unique-constraint names against MySQL's
  64-character identifier limit** — two existing migrations already hit
  this (`stock_transfers`, `inventory_daily_snapshots`; fixed, see
  `app_architecture.md` §1n) and any new multi-column index is at the same
  risk, invisibly, until it's actually applied to MySQL.
- **Cache / session / queue store.** Redis is the expected target; the
  `REDIS_*` variables exist in `.env.example` but nothing is provisioned.
- **Mail.** `MAIL_MAILER=log` means email verification and password reset
  emails go nowhere off-box. A real transport is required for those flows.
- **Queue worker supervision.** `queue:work` under Supervisor/systemd, restarted
  on deploy.
- **Cron.** Now required — five scheduled tasks exist (§6). Without the
  cron entry, none of them silently ever run — no error, they just never
  fire, and `inventory_daily_snapshots`/forecast accuracy/inventory
  recommendations/supplier performance all quietly go stale.
- **Production env.** `APP_DEBUG=false`, `APP_ENV=production`, a raised
  `LOG_LEVEL`, and a real `APP_URL`.
- **Build step.** `npm run build` must run at deploy; `npm run build:ssr` exists
  if SSR is wanted, though SSR is not currently configured or used.
- **Filesystem.** `local` disk today; S3 variables are stubbed but empty.
- **HTTPS / session cookie settings.** `SESSION_ENCRYPT=false` and
  `SESSION_DOMAIN=null` are development defaults; revisit both.
- **The Python ML service (§8).** No hosting, process supervision or private
  networking decided — a second runtime the deploy story doesn't cover at
  all yet. `ML_SERVICE_API_TOKENS`/`ML_SERVICE_TOKEN` must be set to
  matching real values before the service is reachable off `127.0.0.1`.

Update this section as each is decided, and log the decision in
[`task_log.md`](task_log.md).
