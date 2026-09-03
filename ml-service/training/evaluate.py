"""Backtests every algorithm against the same held-out windows.

    python -m training.evaluate

Scores the two statistical baselines (EWMA, seasonal-naive) and the two
trained neural models (DeepAR, TFT) on every forecast window that falls
entirely after the training cutoff, and prints a WAPE/MAE/Bias comparison.

Comparing like with like
------------------------
The baselines return a single **horizon total** (`predicted_qty` = daily rate
x horizon), while the neural models emit a per-day series. The app persists a
horizon total in `forecasts.predicted_qty`, so the headline table sums the
neural per-day output to a total and scores every algorithm on that same
quantity. A per-day table follows for the two models that can produce one --
it is strictly more informative, but only two of the four can be scored on it,
so it cannot be the basis for selection.

The baselines also receive exactly `MlServiceClient.HISTORY_DAYS` (180) days
of history, the same window Laravel sends them in production. Giving them the
full three years here would score a configuration that never actually runs.

Rolling origins, and why the window choice matters
--------------------------------------------------
A single 30-day window is one sample, and which window it is changes the
answer a lot. The covariates the TFT can use that the baselines cannot --
promotions, festival calendar -- only pay off in windows that actually
contain one. The final 30 days of this dataset contain neither, so scoring
there alone understates the covariate-aware models.

This script therefore evaluates every non-overlapping window that starts
after the training cutoff, and prints each window's date range so it is
visible which events (if any) were in scope. Note the hard limit: a model
trained up to day T can only ever be honestly scored after T, so covering a
full seasonal cycle requires *training* with a year-long holdout, not merely
evaluating differently. That is a retraining decision, not an evaluation one,
and it is called out in the output when the windows cover no events.
"""

from __future__ import annotations

import argparse
import json
import warnings
from datetime import timedelta
from pathlib import Path

import numpy as np
import pandas as pd

from app.forecasting import baseline, seasonal_naive
from training import metrics
from training.prediction import is_quantile_loss, point_forecast
from training.dataset import (
    GROUP_ID,
    MAX_PREDICTION_LENGTH,
    TARGET,
    build_datasets,
    load_frame,
)

MODELS_DIR = Path(__file__).resolve().parent.parent / "models"

# Matches MlServiceClient::HISTORY_DAYS on the Laravel side.
BASELINE_HISTORY_DAYS = 180

ALGORITHMS = ("tft", "deepar", "ewma", "seasonal_naive")


def _load_checkpoint(name: str, cls):
    """Resolve this model's checkpoint, newest first.

    Loading a hardcoded `best.ckpt` is not safe: ModelCheckpoint writes
    `best-v1.ckpt` rather than overwriting when a file of that name already
    exists, so `best.ckpt` can be a stale checkpoint from an earlier (possibly
    one-epoch, possibly aborted) run while the real one sits beside it. Train
    clears the directory now, but evaluation still picks by modification time
    and reports what it chose, so a stale file can never be silently scored.
    """
    directory = MODELS_DIR / name
    checkpoints = sorted(
        directory.glob("*.ckpt"), key=lambda p: p.stat().st_mtime, reverse=True
    ) if directory.exists() else []

    if not checkpoints:
        print(f"  ! no checkpoint in {directory} - run training.train first, skipping {name}")
        return None

    if len(checkpoints) > 1:
        print(
            f"  ! {len(checkpoints)} checkpoints in {directory}; using the newest "
            f"({checkpoints[0].name}). Stale files: "
            f"{', '.join(p.name for p in checkpoints[1:])}"
        )

    chosen = checkpoints[0]
    from datetime import datetime

    print(
        f"  {name}: {chosen.name} "
        f"(trained {datetime.fromtimestamp(chosen.stat().st_mtime):%Y-%m-%d %H:%M})"
    )

    return cls.load_from_checkpoint(str(chosen), map_location="cpu")


def _training_config() -> dict:
    """The split/loss settings the checkpoints were produced with.

    Falls back to an empty dict for checkpoints predating this record, which
    reproduces the previous default-holdout behaviour.
    """
    path = MODELS_DIR / "training_summary.json"

    if not path.exists():
        return {}

    try:
        summary = json.loads(path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        print(f"  ! could not read {path}; assuming default split")
        return {}

    # Summaries written before the config block was added are a bare list of
    # model results. Those runs all used the default split, so an empty config
    # is the correct interpretation rather than an error.
    if not isinstance(summary, dict):
        return {}

    return summary.get("config", {})


def _predict_neural(model, dataset):
    """Per-day predictions plus the index identifying each row's series.

    Whether the raw output needs converting from a median to an expected value
    is detected from the checkpoint's own loss, not assumed from the model
    name - see training/prediction.py.
    """
    return point_forecast(model, dataset, return_index=True)


def _actual_matrix(frame: pd.DataFrame, index: pd.DataFrame, horizon: int) -> np.ndarray:
    """Actuals shaped (n_series, horizon), aligned row-for-row with `index`."""
    by_series = {sid: group for sid, group in frame.groupby(GROUP_ID, sort=False)}
    rows = []

    for _, row in index.iterrows():
        series = by_series[row[GROUP_ID]]
        first = int(row["time_idx"])
        window = series[
            (series["time_idx"] >= first) & (series["time_idx"] < first + horizon)
        ][TARGET].to_numpy(dtype=np.float64)

        if window.size != horizon:
            raise SystemExit(
                f"Series {row[GROUP_ID]} has {window.size} days in the window starting "
                f"at {first}, expected {horizon}. The evaluation window is not fully "
                "covered by the data."
            )

        rows.append(window)

    return np.vstack(rows)


def _window_dataset(bundle, frame, origin: int, horizon: int):
    """A prediction dataset whose decoder covers `origin+1 .. origin+horizon`.

    `predict=True` makes pytorch-forecasting take the final `horizon` points of
    each series as the decoder, so truncating the frame at `origin + horizon`
    puts the window exactly where we want it, with the encoder ending at
    `origin` and never seeing beyond it.
    """
    from pytorch_forecasting import TimeSeriesDataSet

    return TimeSeriesDataSet.from_dataset(
        bundle.training,
        frame[frame["time_idx"] <= origin + horizon],
        predict=True,
        stop_randomization=True,
    )


def _baseline_totals(frame: pd.DataFrame, index: pd.DataFrame, horizon: int):
    """Run both statistical baselines over the same series and window."""
    epoch = frame["date"].min()
    by_series = {sid: group for sid, group in frame.groupby(GROUP_ID, sort=False)}

    ewma_totals, seasonal_totals = [], []

    for _, row in index.iterrows():
        series = by_series[row[GROUP_ID]]
        first_forecast_idx = int(row["time_idx"])

        history = series[series["time_idx"] < first_forecast_idx][TARGET].to_numpy()
        history = history[-BASELINE_HISTORY_DAYS:].tolist()

        # The real calendar date the horizon opens on. seasonal_naive resolves
        # every weekday relative to this; defaulting it to today would misalign
        # the whole weekly pattern against a historical window.
        as_of = (epoch + timedelta(days=first_forecast_idx)).date()

        ewma_totals.append(baseline.forecast(history, horizon).predicted_qty)
        seasonal_totals.append(
            seasonal_naive.forecast(history, horizon, as_of=as_of).predicted_qty
        )

    return np.array(ewma_totals), np.array(seasonal_totals)


def _promotion_days(frame: pd.DataFrame, start, end) -> int:
    """How many SKU-days in this window were on promotion.

    Reported per window because it is the single best predictor of whether the
    covariate-aware models had anything to work with.
    """
    window = frame[(frame["date"] >= start) & (frame["date"] <= end)]
    return int(window["on_promotion"].sum())


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--data",
        default=str(
            Path(__file__).resolve().parents[2]
            / "storage" / "app" / "ml" / "training_data.csv"
        ),
    )
    parser.add_argument("--horizon", type=int, default=MAX_PREDICTION_LENGTH)
    args = parser.parse_args()

    warnings.filterwarnings("ignore", category=UserWarning)
    warnings.filterwarnings("ignore", category=FutureWarning)

    from pytorch_forecasting import DeepAR, TemporalFusionTransformer

    if args.horizon != MAX_PREDICTION_LENGTH:
        # The checkpoints were built with a fixed decoder length. Asking for a
        # different horizon would still "work" - the dataset would just keep
        # producing 30-day windows - while the baselines honoured the request,
        # silently comparing two different quantities.
        raise SystemExit(
            f"--horizon must be {MAX_PREDICTION_LENGTH} to match the trained decoder "
            f"length; retrain with a different MAX_PREDICTION_LENGTH to change it."
        )

    print(f"Loading {args.data} ...")
    frame = load_frame(args.data)
    epoch_date = frame["date"].min()
    max_time_idx = int(frame["time_idx"].max())

    # Checkpoints first: the prediction datasets must be rebuilt to match how
    # each model was trained, so the models have to be loaded before the
    # datasets that will feed them.
    tft = _load_checkpoint("tft", TemporalFusionTransformer)
    deepar = _load_checkpoint("deepar", DeepAR)

    config = _training_config()
    holdout_days = config.get("holdout_days")

    if holdout_days is not None:
        print(f"  split: holdout_days={holdout_days} (from training_summary.json)")

    # The target normaliser follows the checkpoint's loss. A count loss trains
    # against an uncentred target; quantile loss against a softplus-transformed
    # one. Building the prediction dataset with the wrong one rescales the
    # encoder inputs and corrupts every prediction silently.
    tft_is_quantile = tft is not None and is_quantile_loss(tft)

    if tft is not None:
        print(f"  tft loss: {type(tft.loss).__name__}")

    tft_bundle = build_datasets(
        frame,
        for_deepar=False,
        holdout_days=holdout_days,
        count_target=not tft_is_quantile,
    )
    deepar_bundle = build_datasets(
        frame, for_deepar=True, holdout_days=holdout_days
    )

    if tft is None and deepar is None:
        raise SystemExit("No trained checkpoints found - run `python -m training.train` first.")

    # Every non-overlapping window beginning after the *validation* cutoff,
    # not merely after the training cutoff. The window immediately following
    # training is the one early stopping selected the checkpoint on, so
    # scoring there would report a figure the model was tuned against - a
    # smaller leak than training on it, but still not a held-out result.
    origins = []
    origin = tft_bundle.validation_cutoff
    while origin + args.horizon <= max_time_idx:
        origins.append(origin)
        origin += args.horizon

    if not origins:
        raise SystemExit(
            "No genuinely held-out window exists: the data ends at the validation "
            "cutoff. Retrain with a longer holdout (build_datasets(holdout_days=...))."
        )

    print(
        f"\n{len(origins)} held-out window(s) of {args.horizon} days, "
        f"all beginning after the validation cutoff at day "
        f"{tft_bundle.validation_cutoff} "
        f"({(epoch_date + timedelta(days=tft_bundle.validation_cutoff)).date()})"
    )

    pooled: dict[str, dict[str, list]] = {
        name: {"actual": [], "predicted": []} for name in ALGORITHMS
    }
    per_day_pooled: dict[str, dict[str, list]] = {
        name: {"actual": [], "predicted": []} for name in ("tft", "deepar")
    }
    windows = []

    for origin in origins:
        start = epoch_date + timedelta(days=origin + 1)
        end = epoch_date + timedelta(days=origin + args.horizon)
        promo_days = _promotion_days(frame, start, end)

        print(f"\n  window {start.date()} -> {end.date()}  ({promo_days} promotion SKU-days)")

        index = None

        for name, model, bundle in (
            ("tft", tft, tft_bundle),
            ("deepar", deepar, deepar_bundle),
        ):
            if model is None:
                continue

            dataset = _window_dataset(bundle, frame, origin, args.horizon)
            predicted, idx = _predict_neural(model, dataset)
            actual = _actual_matrix(frame, idx, args.horizon)

            pooled[name]["actual"].append(actual.sum(axis=1))
            pooled[name]["predicted"].append(predicted.sum(axis=1))
            per_day_pooled[name]["actual"].append(actual.ravel())
            per_day_pooled[name]["predicted"].append(predicted.ravel())

            if index is None:
                index = idx
            elif not idx[GROUP_ID].equals(index[GROUP_ID]):
                # The baselines are scored against whichever index came first.
                # If the two models enumerate series differently, that pairing
                # is wrong for one of them and every comparison below is
                # meaningless while still printing a plausible table.
                raise SystemExit(
                    "Alignment error: the models enumerate series in different "
                    "orders, so the baselines cannot be aligned to both."
                )

        # Both models index the same series in the same order, so the baselines
        # are scored on exactly the rows the models were.
        actuals = _actual_matrix(frame, index, args.horizon).sum(axis=1)
        ewma_totals, seasonal_totals = _baseline_totals(frame, index, args.horizon)

        pooled["ewma"]["actual"].append(actuals)
        pooled["ewma"]["predicted"].append(ewma_totals)
        pooled["seasonal_naive"]["actual"].append(actuals)
        pooled["seasonal_naive"]["predicted"].append(seasonal_totals)

        window_scores = {}
        for name in ALGORITHMS:
            if not pooled[name]["actual"]:
                continue
            window_scores[name] = metrics.score(
                pooled[name]["actual"][-1], pooled[name]["predicted"][-1]
            )

        for name, s in sorted(window_scores.items(), key=lambda kv: kv[1].wape):
            print(f"    {name:<16}WAPE {s.wape:7.4f}   MAE {s.mae:7.3f}   Bias {s.bias:+7.4f}")

        windows.append(
            {
                "start": str(start.date()),
                "end": str(end.date()),
                "promotion_sku_days": promo_days,
                "scores": {k: v.as_dict() for k, v in window_scores.items()},
            }
        )

    results = {}
    for name in ALGORITHMS:
        if not pooled[name]["actual"]:
            continue
        results[name] = metrics.score(
            np.concatenate(pooled[name]["actual"]),
            np.concatenate(pooled[name]["predicted"]),
        )

    per_day = {}
    for name, arrays in per_day_pooled.items():
        if not arrays["actual"]:
            continue
        per_day[name] = metrics.score(
            np.concatenate(arrays["actual"]), np.concatenate(arrays["predicted"])
        )

    print("\n" + "=" * 78)
    print(f"POOLED horizon-total accuracy across {len(origins)} window(s) - lower WAPE is better")
    print("=" * 78)
    print(f"  {'algorithm':<16}{'WAPE':>10}{'MAE':>12}{'Bias':>12}")
    print("  " + "-" * 48)
    for name, s in sorted(results.items(), key=lambda kv: kv[1].wape):
        print(f"  {name:<16}{s.wape:>10.4f}{s.mae:>12.3f}{s.bias:>+12.4f}")

    if per_day:
        print("\n" + "=" * 78)
        print("Per-day accuracy (neural models only - baselines emit no daily series)")
        print("=" * 78)
        for name, s in sorted(per_day.items(), key=lambda kv: kv[1].wape):
            print(f"  {name:<16}{s.wape:>10.4f}{s.mae:>12.3f}{s.bias:>+12.4f}")

    winner = min(results.items(), key=lambda kv: kv[1].wape)
    baselines = {k: v for k, v in results.items() if k in ("ewma", "seasonal_naive")}
    best_baseline = min(baselines.items(), key=lambda kv: kv[1].wape)

    print("\n" + "=" * 78)
    print(f"Best overall:  {winner[0]} (WAPE {winner[1].wape:.4f})")
    print(f"Best baseline: {best_baseline[0]} (WAPE {best_baseline[1].wape:.4f})")

    if winner[0] in ("ewma", "seasonal_naive"):
        print(
            "\n  The trained models did NOT beat the statistical baseline here.\n"
            "  Do not promote them into serving. Report this result as-is."
        )
    else:
        print(
            f"  Improvement over best baseline: "
            f"{1 - (winner[1].wape / best_baseline[1].wape):+.1%} WAPE"
        )

    if all(w["promotion_sku_days"] == 0 for w in windows):
        print(
            "\n  CAVEAT: no evaluated window contains a promotion, and the held-out\n"
            "  period covers no festival either. The covariate-aware models are being\n"
            "  judged on exactly the conditions where they have the least to offer.\n"
            "  A fair seasonal comparison needs RETRAINING with a year-long holdout,\n"
            "  not a different evaluation split."
        )
    print("=" * 78)

    output = MODELS_DIR / "evaluation.json"
    output.write_text(
        json.dumps(
            {
                "horizon_days": args.horizon,
                "windows": windows,
                "pooled_horizon_total": {k: v.as_dict() for k, v in results.items()},
                "pooled_per_day": {k: v.as_dict() for k, v in per_day.items()},
                "best": winner[0],
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    print(f"\nWritten to {output}")


if __name__ == "__main__":
    main()
