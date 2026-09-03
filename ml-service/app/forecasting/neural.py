"""Serving path for the trained neural models (TFT, DeepAR).

Unlike `baseline.py` and `seasonal_naive.py`, which are arithmetic over a list
of integers, this module runs a real trained checkpoint. That difference shows
up in three places, and each one is a way to get confidently wrong numbers:

1. **A checkpoint alone is not a model.** `TimeSeriesDataSet` carries *fitted*
   state — the categorical encoders mapping a sku_id to an embedding row, the
   per-series target normaliser, the continuous scalers. Feeding a model inputs
   encoded any other way produces plausible nonsense with no error. So training
   persists `dataset_params.pt` beside the checkpoint (see
   `training/train.py::save_serving_artifacts`) and serving rebuilds the exact
   pipeline with `TimeSeriesDataSet.from_parameters`.

2. **`time_idx` is a feature, not bookkeeping.** It is a known-future real the
   model reads directly, defined as days since the first date of the *training*
   frame. Serving must resolve calendar dates to that same origin; recomputing
   it per request would place every forecast at time_idx≈0 and lie to the model
   about what date it is.

3. **The decoder length is fixed at training time.** The checkpoint can only
   emit `max_prediction_length` days. Anything longer is refused rather than
   extrapolated — see `UnsupportedSeries`.

Every refusal in this module raises `UnsupportedSeries`, which `app/main.py`
catches and answers with a baseline instead, echoing the algorithm that
actually ran back to Laravel. A neural forecast is never silently degraded into
a worse one under the same name.

The two statistical baselines keep working with none of this present: the
imports here are local to the functions that need them, so a deployment without
torch or without checkpoints still serves `ewma` and `seasonal_naive`.
"""

from __future__ import annotations

import threading
from datetime import date, timedelta
from pathlib import Path

from app.forecasting.baseline import MIN_RELIABLE_HISTORY_DAYS, BaselineForecast

MODELS_DIR = Path(__file__).resolve().parents[2] / "models"

# Model names that resolve to a trained checkpoint rather than a formula.
NEURAL_ALGORITHMS = ("tft", "deepar")

# The group column the models were trained with. Must match
# `training/dataset.py::GROUP_ID` — duplicated rather than imported so the
# serving path does not depend on the pandas-heavy training module for a
# string constant.
GROUP_ID = "series_id"

# The quantile pair used for the reported interval. 0.1/0.9 is an 80% central
# interval — deliberately narrower than the baselines' ±1.5σ, because these
# come from the model's own predictive distribution rather than an assumption
# that demand is normally distributed.
LOWER_QUANTILE = 0.1
UPPER_QUANTILE = 0.9

# Converts an 80% central interval back to a standard deviation, assuming
# approximate normality *only for the confidence heuristic* — never for the
# reported interval, which stays the model's own quantiles.
_INTERVAL_TO_SIGMA = 2.5631031310892016  # z(0.9) - z(0.1)


class UnsupportedSeries(RuntimeError):
    """This series cannot be served by this model, with a reason a caller can log.

    Raised rather than returning a degraded guess: an unknown SKU, a horizon
    longer than the decoder, or too little history are all cases where the
    model has nothing real to say, and saying it quietly would be worse than
    falling back to a baseline that is honest about being simple.
    """


class _LoadedModel:
    """A checkpoint plus the fitted feature pipeline it was trained with."""

    def __init__(self, name: str, model, params: dict) -> None:
        self.name = name
        self.model = model
        self.dataset_parameters = params["dataset_parameters"]
        self.epoch_date = date.fromisoformat(params["epoch_date"])
        self.last_training_date = date.fromisoformat(params["last_training_date"])
        self.max_encoder_length = int(self.dataset_parameters["max_encoder_length"])
        self.max_prediction_length = int(self.dataset_parameters["max_prediction_length"])
        self.min_encoder_length = int(self.dataset_parameters["min_encoder_length"])

    def known_categories(self, column: str) -> set[str]:
        """The values this column's encoder actually learned.

        An id outside this set maps to the encoder's NaN bucket. That does not
        raise — `add_nan=True` was set at training time so a late-launching SKU
        would not crash the validation split — which is exactly why it has to be
        checked explicitly here instead. An unknown SKU has no learned
        embedding, so its "forecast" would be the average of everything the
        model has ever seen wearing this SKU's name.
        """
        encoder = self.dataset_parameters["categorical_encoders"][column]

        return {str(key) for key in encoder.classes_}


_CACHE: dict[str, _LoadedModel] = {}
_CACHE_LOCK = threading.Lock()


def _load(name: str) -> _LoadedModel:
    """Load and memoise a checkpoint. Raises UnsupportedSeries if unavailable.

    Guarded by a lock because uvicorn serves requests from a thread pool and
    two concurrent first-requests would otherwise both pay the multi-second
    load. Deliberately lazy rather than loaded at import: the service must
    still start and serve baselines on a machine with no checkpoints.
    """
    cached = _CACHE.get(name)

    if cached is not None:
        return cached

    with _CACHE_LOCK:
        if name in _CACHE:
            return _CACHE[name]

        directory = MODELS_DIR / name
        checkpoint = directory / "best.ckpt"
        params_path = directory / "dataset_params.pt"

        if not checkpoint.exists() or not params_path.exists():
            raise UnsupportedSeries(
                f"{name}: no trained checkpoint at {directory} "
                "(run `python -m training.train`)"
            )

        try:
            import torch
            from pytorch_forecasting import DeepAR, TemporalFusionTransformer
        except ImportError as exc:  # pragma: no cover - depends on install profile
            raise UnsupportedSeries(f"{name}: torch/pytorch-forecasting not installed") from exc

        cls = TemporalFusionTransformer if name == "tft" else DeepAR

        # weights_only=False: the payload contains fitted sklearn-style encoders
        # and normalisers, not just tensors. This file is written by our own
        # training run and read from disk beside the checkpoint.
        params = torch.load(params_path, map_location="cpu", weights_only=False)
        model = cls.load_from_checkpoint(str(checkpoint), map_location="cpu")
        model.eval()

        loaded = _LoadedModel(name, model, params)
        _CACHE[name] = loaded

        return loaded


def reset_cache() -> None:
    """Drop loaded checkpoints. Used by tests and after a retraining run."""
    with _CACHE_LOCK:
        _CACHE.clear()


def _calendar_features(day: date) -> dict[str, float | str]:
    """The same known-future covariates `training/dataset.py` derives.

    Duplicated deliberately rather than imported: `training/` depends on pandas
    and is not installed in a serving-only deployment. The two must change
    together, and `tests/test_neural.py` asserts they agree.
    """
    days_in_month = (
        date(day.year + (day.month == 12), (day.month % 12) + 1, 1) - timedelta(days=1)
    ).day

    return {
        "day_of_week": str(day.weekday()),
        "month": str(day.month),
        "day_of_month": float(day.day),
        "week_of_year": float(day.isocalendar()[1]),
        "is_payday": float(day.day >= days_in_month - 2 or day.day <= 3),
        "is_avurudu": float(day.month == 4 and 5 <= day.day <= 14),
        "is_vesak": float(day.month == 5 and 12 <= day.day <= 19),
        "is_deepavali": float(
            (day.month == 10 and day.day >= 25) or (day.month == 11 and day.day <= 5)
        ),
        "is_christmas": float(day.month == 12 and 12 <= day.day <= 25),
    }


def _padded(values, length: int) -> list[float]:
    """Right-align a covariate series to the target length.

    A covariate that arrived shorter than `daily_sold_qty` is padded at the
    *front* with zeros, because both series end at the same day — the forecast
    origin. Padding the back would shift the whole covariate off by the
    difference and silently attribute last week's stockout to last month.
    """
    values = [float(value) for value in (values or [])]

    if len(values) >= length:
        return values[-length:]

    return [0.0] * (length - len(values)) + values


def _build_frame(loaded: _LoadedModel, series):
    """Assemble the encoder history + decoder future rows the model reads."""
    import numpy as np
    import pandas as pd

    features = series.features
    history = [float(qty) for qty in series.daily_sold_qty]
    length = len(history)

    start = features.series_start_date
    dates = [start + timedelta(days=offset) for offset in range(length)]

    # The forecast origin is the day after the last *observed* day, not
    # today(). The snapshot pipeline can be a day or more behind, and anchoring
    # to today would leave a hole between the encoder's last row and the
    # decoder's first — which TimeSeriesDataSet fills by interpolation, quietly
    # inventing history.
    origin = dates[-1] + timedelta(days=1)

    rows = []

    available = _padded(features.available_qty, length)
    received = _padded(features.received_qty, length)
    stockout_minutes = _padded(features.stockout_minutes, length)
    on_promotion = _padded(features.on_promotion, length)
    promotion_discount = _padded(features.promotion_discount, length)

    for index, day in enumerate(dates):
        rows.append(
            {
                "date": day,
                "sold_qty": max(0.0, history[index]),
                "available_qty": available[index],
                "received_qty": received[index],
                "stockout_minutes": stockout_minutes[index],
                "stockout_flag": 1.0 if stockout_minutes[index] > 0 else 0.0,
                "on_promotion": on_promotion[index],
                "promotion_discount": promotion_discount[index],
                **_calendar_features(day),
            }
        )

    # The decoder always runs the full trained length even when the caller asked
    # for fewer days; the model cannot emit a shorter sequence. The extra days
    # are computed and discarded in _predict.
    future_promotion = _padded(features.future_on_promotion, loaded.max_prediction_length)
    future_discount = _padded(features.future_promotion_discount, loaded.max_prediction_length)

    for offset in range(loaded.max_prediction_length):
        day = origin + timedelta(days=offset)
        rows.append(
            {
                "date": day,
                # Placeholder target. With predict=True these rows are the
                # decoder window, so the model never reads them — but the
                # column has to exist and be numeric.
                "sold_qty": 0.0,
                # Past-observed covariates are unknowable for a future day and
                # are not fed to the decoder. Present as columns only.
                "available_qty": 0.0,
                "received_qty": 0.0,
                "stockout_minutes": 0.0,
                "stockout_flag": 0.0,
                "on_promotion": future_promotion[offset],
                "promotion_discount": future_discount[offset],
                **_calendar_features(day),
            }
        )

    frame = pd.DataFrame(rows)

    frame["series_id"] = f"{series.warehouse_id}-{series.sku_id}"
    frame["warehouse_id"] = str(series.warehouse_id)
    frame["sku_id"] = str(series.sku_id)
    frame["category_id"] = str(features.category_id if features.category_id is not None else -1)
    frame["brand_id"] = str(features.brand_id if features.brand_id is not None else -1)
    frame["log_selling_price"] = np.float32(np.log1p(max(0.0, features.selling_price)))

    frame["time_idx"] = [(day - loaded.epoch_date).days for day in frame["date"]]

    for column in (
        "sold_qty",
        "available_qty",
        "received_qty",
        "stockout_minutes",
        "stockout_flag",
        "on_promotion",
        "promotion_discount",
        "day_of_month",
        "week_of_year",
        "is_payday",
        "is_avurudu",
        "is_vesak",
        "is_deepavali",
        "is_christmas",
    ):
        frame[column] = frame[column].astype(np.float32)

    return frame.sort_values("time_idx").reset_index(drop=True)


def _check_servable(loaded: _LoadedModel, series, horizon_days: int) -> None:
    """Refuse the cases where this model has nothing real to say."""
    if series.features is None:
        raise UnsupportedSeries(
            f"{loaded.name}: requires the `features` block "
            "(category, brand, price, stockout and promotion history)"
        )

    if horizon_days > loaded.max_prediction_length:
        raise UnsupportedSeries(
            f"{loaded.name}: trained to predict {loaded.max_prediction_length} days, "
            f"asked for {horizon_days}"
        )

    history_days = len(series.daily_sold_qty)

    if history_days < loaded.min_encoder_length:
        raise UnsupportedSeries(
            f"{loaded.name}: needs at least {loaded.min_encoder_length} days of "
            f"history, got {history_days}"
        )

    series_id = f"{series.warehouse_id}-{series.sku_id}"

    if series_id not in loaded.known_categories("series_id"):
        raise UnsupportedSeries(
            f"{loaded.name}: series {series_id} was not in the training data "
            "(cold start — no learned embedding)"
        )


def plan_batch(loaded, series_list, horizon_days: int):
    """Decide which series go to the model and which are refused.

    Split out from `forecast_many` because it is the part with the rules in it
    and the part worth testing without a checkpoint present — everything after
    it is tensor plumbing.

    Returns `({series_id: position}, {position: UnsupportedSeries})`. The map is
    insertion-ordered, so the frames built from it line up with it.
    """
    position_by_series_id: dict[str, int] = {}
    refusals: dict[int, UnsupportedSeries] = {}

    for position, series in enumerate(series_list):
        series_id = f"{series.warehouse_id}-{series.sku_id}"

        # Checked before servability: a pair sent twice in one batch is a
        # malformed request whatever its history looks like, and both copies
        # would collide in the model's group index and silently receive
        # whichever prediction landed last.
        if series_id in position_by_series_id:
            refusals[position] = UnsupportedSeries(
                f"{loaded.name}: {series_id} appears more than once in this batch"
            )
            continue

        try:
            _check_servable(loaded, series, horizon_days)
        except UnsupportedSeries as exc:
            refusals[position] = exc
            continue

        position_by_series_id[series_id] = position

    return position_by_series_id, refusals


def _quantile_index(model, level: float) -> int:
    """Position of a quantile level in the model's own configured list."""
    quantiles = list(getattr(model.loss, "quantiles", []) or [])

    if not quantiles:
        raise UnsupportedSeries("model exposes no quantiles for an interval")

    return min(range(len(quantiles)), key=lambda i: abs(quantiles[i] - level))


def forecast(series, horizon_days: int, *, algorithm: str) -> BaselineForecast:
    """Predict total demand over `horizon_days` for a single series.

    Convenience wrapper over `forecast_many`. **Prefer `forecast_many` for a
    batch** — see its docstring for why calling this in a loop is not merely
    slower but unusable at real batch sizes.
    """
    outcome = forecast_many([series], horizon_days, algorithm=algorithm)[0]

    if isinstance(outcome, UnsupportedSeries):
        raise outcome

    return outcome


def forecast_many(
    series_list, horizon_days: int, *, algorithm: str
) -> list[BaselineForecast | UnsupportedSeries]:
    """Predict for many series in **one** forward pass, ordered like the input.

    Returns a `BaselineForecast` per input series, or an `UnsupportedSeries`
    (returned, not raised) for any the model declines — so one unservable SKU
    never costs the rest of the batch its forecast.

    **Why batching is not an optimisation here.** Each `model.predict()` call
    spins up a Lightning trainer, builds a dataloader and runs a full
    predict loop. Doing that per series meant a 441-pair forecast run issued
    ~880 of them and blew the client's 30-second HTTP timeout with the run
    marked Failed — the first thing that happened when this was pointed at
    real data. One combined frame gives one dataset and two passes over it
    (the point forecast, and the quantiles for the interval), independent of
    how many SKUs are in the batch.
    """
    import numpy as np
    import pandas as pd
    from pytorch_forecasting import TimeSeriesDataSet

    from training.prediction import point_forecast

    outcomes: list[BaselineForecast | UnsupportedSeries | None] = [None] * len(series_list)

    try:
        loaded = _load(algorithm)
    except UnsupportedSeries as exc:
        # A missing checkpoint is one fact about the deployment, not per series.
        return [exc] * len(series_list)

    position_by_series_id, refusals = plan_batch(loaded, series_list, horizon_days)

    for position, refusal in refusals.items():
        outcomes[position] = refusal

    if not position_by_series_id:
        return [outcome for outcome in outcomes]  # type: ignore[misc]

    frames = [
        _build_frame(loaded, series_list[position])
        for position in position_by_series_id.values()
    ]

    combined = pd.concat(frames, ignore_index=True)

    dataset = TimeSeriesDataSet.from_parameters(
        loaded.dataset_parameters,
        combined,
        predict=True,
        stop_randomization=True,
    )

    # return_index is what makes this safe: the dataloader does not preserve
    # input order, so predictions are matched back by the group id the index
    # reports rather than by position. Aligning by position would quietly give
    # SKUs each other's forecasts.
    predicted, index = point_forecast(loaded.model, dataset, return_index=True)
    predicted = np.asarray(predicted).reshape(len(index), -1)

    raw_quantiles = loaded.model.predict(
        dataset,
        mode="quantiles",
        trainer_kwargs={"accelerator": "cpu", "enable_progress_bar": False},
    )
    raw_quantiles = (
        raw_quantiles.cpu().numpy() if hasattr(raw_quantiles, "cpu") else np.asarray(raw_quantiles)
    )
    raw_quantiles = raw_quantiles.reshape(len(index), -1, raw_quantiles.shape[-1])

    lower_column = _quantile_index(loaded.model, LOWER_QUANTILE)
    upper_column = _quantile_index(loaded.model, UPPER_QUANTILE)

    for row, series_id in enumerate(index[GROUP_ID].astype(str)):
        position = position_by_series_id.get(series_id)

        if position is None:
            continue

        outcomes[position] = _summarise(
            predicted_daily=np.clip(predicted[row][:horizon_days], 0, None),
            lower_daily=np.clip(raw_quantiles[row][:horizon_days, lower_column], 0, None),
            upper_daily=np.clip(raw_quantiles[row][:horizon_days, upper_column], 0, None),
            history_days=len(series_list[position].daily_sold_qty),
        )

    # A series that was accepted but came back with no row is a real alignment
    # failure, not something to paper over with a zero.
    for position, outcome in enumerate(outcomes):
        if outcome is None:
            outcomes[position] = UnsupportedSeries(
                f"{algorithm}: the model returned no prediction for this series"
            )

    return outcomes  # type: ignore[return-value]


def _summarise(*, predicted_daily, lower_daily, upper_daily, history_days: int) -> BaselineForecast:
    """Turn one series' per-day output into the horizon totals the app stores."""
    import numpy as np

    predicted_qty = float(predicted_daily.sum())

    # Daily intervals are aggregated the way the baselines aggregate daily
    # variance — in quadrature, not by summing widths. Summing the per-day
    # bounds would assume every day errs in the same direction and produce a
    # band several times too wide over a 30-day horizon.
    sigma_daily = (upper_daily - lower_daily) / _INTERVAL_TO_SIGMA
    horizon_sigma = float(np.sqrt(np.square(sigma_daily).sum()))
    margin = _INTERVAL_TO_SIGMA / 2 * horizon_sigma

    return BaselineForecast(
        predicted_qty=round(predicted_qty, 2),
        lower_qty=max(0.0, round(predicted_qty - margin, 2)),
        upper_qty=round(predicted_qty + margin, 2),
        confidence_score=_confidence_score(
            predicted_daily=predicted_daily,
            sigma_daily=sigma_daily,
            history_days=history_days,
        ),
    )


def _confidence_score(*, predicted_daily, sigma_daily, history_days: int) -> int:
    """Score on the same 10–95 scale the baselines use.

    Deliberately the same formula and the same bounds: the Forecasts page shows
    one confidence column across every algorithm, so a 70 from the TFT and a 70
    from EWMA have to mean the same thing to a planner. The input differs — a
    coefficient of variation from the model's own predictive distribution,
    rather than from raw historical volatility.
    """
    import numpy as np

    mean_prediction = float(np.mean(predicted_daily)) if len(predicted_daily) else 0.0
    mean_sigma = float(np.mean(sigma_daily)) if len(sigma_daily) else 0.0

    coefficient_of_variation = (mean_sigma / mean_prediction) if mean_prediction > 0 else 1.0

    score = 95.0 - min(70.0, coefficient_of_variation * 50.0)

    if history_days < MIN_RELIABLE_HISTORY_DAYS:
        score -= 25.0

    return max(10, min(95, round(score)))
