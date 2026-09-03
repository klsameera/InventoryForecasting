"""A second deterministic baseline: day-of-week seasonal averaging.

Genuinely different from `baseline.py`'s exponentially-weighted moving
average — this captures **weekly** seasonality (e.g. weekend spikes) that
EWMA ignores entirely, by averaging each weekday's own historical demand
separately rather than blending every day into one smoothed rate. Exists so
Phase 10's "automatic model selection" (app_plan.md §86) has two real,
distinct algorithms to choose between per SKU, not a placeholder standing in
for a second model.

**Assumes the last entry in `daily_sold_qty` is *yesterday*** relative to
`date.today()` — the same "history ending now" assumption
`MlServiceClient`/`ForecastRunService` already make when building a series
on the Laravel side — so each historical day, and each day in the forecast
horizon, can be mapped to a real calendar weekday.
"""

from __future__ import annotations

from datetime import date, timedelta
from statistics import fmean, pstdev

from app.forecasting.baseline import MIN_RELIABLE_HISTORY_DAYS, BaselineForecast


def forecast(
    daily_sold_qty: list[float],
    horizon_days: int,
    as_of: date | None = None,
) -> BaselineForecast:
    """Predict total demand over the next `horizon_days` by summing each
    forecast day's own weekday average, given a chronological (oldest-first)
    list of daily sold quantities ending yesterday.

    `as_of` is the first day of the forecast horizon, i.e. the day *after*
    the last entry in `daily_sold_qty`. It defaults to `date.today()`, which
    is correct for live serving and preserves this function's original
    behaviour exactly. It must be passed explicitly when backtesting from a
    historical cutoff (`training/evaluate.py`): every weekday in this model
    is resolved relative to it, so leaving it at today's date while scoring a
    window from last year silently misaligns the entire weekly pattern and
    makes the model look far worse than it is.
    """
    if not daily_sold_qty or all(qty == 0 for qty in daily_sold_qty):
        return BaselineForecast(
            predicted_qty=0.0, lower_qty=0.0, upper_qty=0.0, confidence_score=10
        )

    today = as_of or date.today()
    weekday_averages = _weekday_averages(daily_sold_qty, today)

    predicted_qty = round(
        sum(
            weekday_averages[(today + timedelta(days=offset)).weekday()]
            for offset in range(horizon_days)
        ),
        2,
    )

    residuals = _residuals(daily_sold_qty, weekday_averages, today)
    volatility = pstdev(residuals) if len(residuals) > 1 else 0.0
    # Same sqrt(horizon_days) variance-aggregation approach as baseline.py.
    horizon_std = volatility * (horizon_days**0.5)
    margin = round(1.5 * horizon_std, 2)

    lower_qty = max(0.0, round(predicted_qty - margin, 2))
    upper_qty = round(predicted_qty + margin, 2)

    confidence_score = _confidence_score(daily_sold_qty, residuals)

    return BaselineForecast(
        predicted_qty=predicted_qty,
        lower_qty=lower_qty,
        upper_qty=upper_qty,
        confidence_score=confidence_score,
    )


def _weekday_averages(daily_sold_qty: list[float], as_of: date) -> dict[int, float]:
    """Maps weekday (0=Monday..6=Sunday) to that weekday's historical
    average quantity. A weekday with no observed history in this window
    falls back to the overall mean rather than a fabricated zero.

    `as_of` is the first forecast day; the history is therefore taken to end
    the day before it.
    """
    n = len(daily_sold_qty)
    yesterday = as_of - timedelta(days=1)

    buckets: dict[int, list[float]] = {weekday: [] for weekday in range(7)}

    for index, qty in enumerate(daily_sold_qty):
        day = yesterday - timedelta(days=(n - 1 - index))
        buckets[day.weekday()].append(qty)

    overall_mean = fmean(daily_sold_qty)

    return {
        weekday: fmean(values) if values else overall_mean
        for weekday, values in buckets.items()
    }


def _residuals(
    daily_sold_qty: list[float], weekday_averages: dict[int, float], as_of: date
) -> list[float]:
    """Actual minus that day's own weekday average — the seasonal model's
    unexplained variance, used for the confidence interval and score
    instead of raw day-to-day volatility (which would double-count the
    weekly pattern this model already accounts for).
    """
    n = len(daily_sold_qty)
    yesterday = as_of - timedelta(days=1)

    return [
        qty - weekday_averages[(yesterday - timedelta(days=(n - 1 - index))).weekday()]
        for index, qty in enumerate(daily_sold_qty)
    ]


def _confidence_score(daily_sold_qty: list[float], residuals: list[float]) -> int:
    """Same shape as baseline.py's own scoring, but driven by *residual*
    volatility (how well the weekday pattern explains the data) rather
    than raw volatility.
    """
    mean = fmean(daily_sold_qty)
    residual_volatility = pstdev(residuals) if len(residuals) > 1 else 0.0
    coefficient_of_variation = (residual_volatility / mean) if mean > 0 else 1.0

    score = 95.0 - min(70.0, coefficient_of_variation * 50.0)

    if len(daily_sold_qty) < MIN_RELIABLE_HISTORY_DAYS:
        score -= 25.0

    return max(10, min(95, round(score)))
