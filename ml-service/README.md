# InventoryForecasting forecasting service

This service serves **four** algorithms — two statistical, two trained:

| Algorithm | Module | Needs |
| --- | --- | --- |
| `ewma` | `app/forecasting/baseline.py` | the daily series only |
| `seasonal_naive` | `app/forecasting/seasonal_naive.py` | the daily series only |
| `tft` | `app/forecasting/neural.py` | the `features` block + `models/tft/` |
| `deepar` | `app/forecasting/neural.py` | the `features` block + `models/deepar/` |

Laravel decides which to request per SKU (`ModelSelectionService`) from real
scored accuracy history; this service has no opinion and only dispatches.

**Read this before quoting a neural forecast.** The trained models learn from
`HistoricalTransactionSeeder`'s *generated* three-year history, not real sales.
Their accuracy measures how well they recover that generator's own formula.
That is a genuine test of the pipeline and a genuine comparison between
architectures — it is **not** evidence of real-world accuracy, and must not be
presented as such. This was the standing reason not to serve them at all; the
decision was reversed on request, and the caveat did not go away with it. See
`docs/app_architecture.md` §1q.

**They are also off by default.** `ML_DEFAULT_ALGORITHM` is `ewma` unless set,
so a deployment that changes nothing runs only the baselines.

## Refusal, not degradation

A trained model answers with a **baseline** — never an error, never a degraded
neural number — whenever it cannot honestly serve a series:

- no `features` block
- a horizon longer than its fixed 30-day decoder
- fewer than 45 days of history
- a SKU the encoder never saw during training (cold start)
- a missing or unloadable checkpoint

The response echoes the algorithm that **actually ran** plus a
`fallback_reason`, and Laravel stamps the forecast row from that echo. So a
fallback is never filed under the neural model's name, and never accumulates
accuracy history the model did not earn.

This is also why the service still works with no `models/` directory at all:
the two baselines need neither torch nor a checkpoint.

## What's real here

- The FastAPI service, its `/forecast/run` and `/models` contracts, and
  `/health`.
- The Laravel side: `ml_model_versions`, `ml_forecast_runs`, `forecasts`,
  `forecast_accuracy` tables; the queued `RunDemandForecast` job; the
  `/forecast-run` and `/forecast` pages; the nightly
  `app:score-forecast-accuracy` command that checks predictions against what
  Phase 4's `inventory_daily_snapshots` actually recorded; monthly
  `app:train-forecast-model` retraining.
- All four algorithms and per-series dispatch, with the fallback contract above.
- Cold-start and early-SKU handling on the Laravel side. Laravel can replace or
  blend the submitted series with real category+size, brand+category, or
  category peer history and persists the source used on each forecast. Such a
  pair is forced onto a baseline — a peer-derived series read under this SKU's
  own embedding would describe one product using another's sales.

## What isn't

- No feature store, no `demand_daily_features` table (app_plan.md §25). The
  baselines read `daily_sold_qty` and nothing more; the neural models read the
  `features` block Laravel assembles per request, which is built to match the
  training export column-for-column but is not a persisted store.
- The Python service does not choose forecast sources, maturity, or algorithm.
  It receives what Laravel selected and runs it, or refuses it.
- **One `ml_model_versions` row per algorithm, reused across retrainings.**
  Retraining overwrites `best.ckpt` in place and keeps version `v1`, so a
  forecast made by an older checkpoint is indistinguishable from a newer one.
  Since these models decay with age, a real deployment wants a version per
  training run. Not built.
- No censored-demand correction. Stockouts ship as covariates the TFT can
  condition on, which is not the same as modelling the censoring.

## The training pipeline (`training/`)

Since the neural work, this service also contains a real training pipeline —
`training/train.py` trains **DeepAR** and a **Temporal Fusion Transformer**
via `pytorch-forecasting`, and `training/evaluate.py` backtests all four
algorithms on a held-out window with WAPE/MAE/Bias. Checkpoints land in
`models/`.

Three things to be clear about:

1. **Training writes two artifacts per model, and both are required to serve
   it.** `best.ckpt` is the weights; `dataset_params.pt` is the *fitted feature
   pipeline* — the categorical encoders mapping a `sku_id` to an embedding row,
   the per-series target normaliser, the scalers, and the `epoch_date` that
   `time_idx` is measured from. A model fed inputs encoded any other way
   returns plausible nonsense with no error, so `neural.py` reports a
   checkpoint without its params as unavailable rather than loading it.
2. **Training data is synthetic.** The models train on the output of Laravel's
   `HistoricalTransactionSeeder`, which generates demand from an explicit
   formula (seasonality, day-of-week curve, festival calendar, price
   elasticity, promotion lift). A model scoring well here has learned to
   recover that formula. That is a genuine test of the pipeline, and a genuine
   comparison *between* models, but it is not evidence of real-world accuracy.
   The application still has no production sales history.
3. **The result is whatever it is.** `evaluate.py` prints, in plain terms,
   when the trained models fail to beat the statistical baseline, and does not
   round that away.

```bash
pip install -r requirements-training.txt --extra-index-url https://download.pytorch.org/whl/cpu
python -m training.train      # CPU, ~3-4 min/epoch at default settings
python -m training.evaluate
```

The dataset comes from Laravel: `php artisan app:export-ml-training-data`
writes `storage/app/ml/training_data.csv`. See `docs/app_architecture.md` §1p
for the feature set, the DeepAR/TFT capability difference, and the known
limitations (censored demand, no daily price series, cold-start SKUs), and §1q
for how a checkpoint is actually served.

**In production, retrain through Laravel** so the run is scheduled and logged:

```bash
php artisan app:train-forecast-model     # export + retrain
php artisan app:forecast-model-status    # what is servable, and what is in use
```

This runs monthly on the schedule, and that cadence is **load-bearing, not
housekeeping**: the TFT beats the baselines by ~8% while freshly trained and is
*worse* than them by about four months, because the baselines re-derive from
the trailing 180 days on every call and cannot decay. A stale checkpoint keeps
returning confident numbers while being the wrong choice.

Restart the service after training — checkpoints are memoised on first use.

## Running it

```bash
cd ml-service
python -m venv .venv
.venv/Scripts/activate   # Windows; use .venv/bin/activate on macOS/Linux
pip install -r requirements.txt
cp .env.example .env
uvicorn app.main:app --host 127.0.0.1 --port 8090 --reload
```

Then point Laravel at it — `ML_SERVICE_URL=http://127.0.0.1:8090` in the
app's `.env` (already the default in `.env.example` if unset).

## Tests

```bash
pytest
```

## Authentication

Empty `ML_SERVICE_API_TOKENS` (the default) means no auth is enforced — fine
for local development where this only ever listens on `127.0.0.1`. Before
this runs anywhere reachable off localhost, set `ML_SERVICE_API_TOKENS` to
one or more comma-separated tokens here, and set the matching
`ML_SERVICE_TOKEN` in the Laravel app's own `.env` — `MlServiceClient`
already sends it as `Authorization: Bearer <token>` whenever it's set.
app_plan.md §74 also calls for private networking (the service shouldn't be
publicly reachable at all, token or not); that's an infrastructure/hosting
decision, not something this scaffold can set up.
