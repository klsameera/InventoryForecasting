"""A deterministic, non-ML baseline forecast.

**This is explicitly not the model app_plan.md Phases 5-10 describe.** It is
a exponentially-weighted moving average over each SKU's own recent daily
sales history, with a confidence interval derived from the same history's
volatility. It exists so the Laravel <-> Python integration (the request
contract, the queued job, the `forecasts`/`ml_forecast_runs` tables, the
UI) can be built and exercised end to end before any real forecasting model
exists — swap this module out once one does; nothing upstream of it needs
to change (see MlServiceClient's docblock on the Laravel side).

No training, no persisted model weights, no external ML library dependency
- this is plain arithmetic over a list of integers, which is exactly what
it claims to be.
"""

from __future__ import annotations

from dataclasses import dataclass
from statistics import fmean, pstdev

# More recent days influence the predicted daily rate more than older ones.
# 0.97 means a day 30 days ago carries roughly 40% of the weight of today.
DECAY = 0.97

# Below this many days of history, a forecast is "cold start"-ish (app_plan.md
# §36's "New" demand class) and confidence is capped low regardless of how
# stable the available data looks.
MIN_RELIABLE_HISTORY_DAYS = 14


@dataclass(frozen=True)
class BaselineForecast:
    predicted_qty: float
    lower_qty: float
    upper_qty: float
    confidence_score: int


def forecast(daily_sold_qty: list[float], horizon_days: int) -> BaselineForecast:
    """Predict total demand over the next `horizon_days`, given a
    chronological (oldest-first) list of daily sold quantities — usually
    whole units, but a Cold-start/Early pair's series may be a fractional
    peer-averaged or blended value (see schemas.py's SeriesInput docstring).
    """
    if not daily_sold_qty or all(qty == 0 for qty in daily_sold_qty):
        return BaselineForecast(
            predicted_qty=0.0, lower_qty=0.0, upper_qty=0.0, confidence_score=10
        )

    daily_rate = _weighted_daily_rate(daily_sold_qty)
    predicted_qty = round(daily_rate * horizon_days, 2)

    volatility = pstdev(daily_sold_qty) if len(daily_sold_qty) > 1 else 0.0
    # Variance accumulates across the horizon; scale the daily standard
    # deviation by sqrt(horizon_days) rather than horizon_days itself, the
    # standard approach for aggregating independent daily variances.
    horizon_std = volatility * (horizon_days**0.5)
    margin = round(1.5 * horizon_std, 2)

    lower_qty = max(0.0, round(predicted_qty - margin, 2))
    upper_qty = round(predicted_qty + margin, 2)

    confidence_score = _confidence_score(daily_sold_qty, daily_rate, volatility)

    return BaselineForecast(
        predicted_qty=predicted_qty,
        lower_qty=lower_qty,
        upper_qty=upper_qty,
        confidence_score=confidence_score,
    )


def _weighted_daily_rate(daily_sold_qty: list[float]) -> float:
    n = len(daily_sold_qty)
    weights = [DECAY ** (n - 1 - i) for i in range(n)]
    weighted_sum = sum(qty * weight for qty, weight in zip(daily_sold_qty, weights))
    total_weight = sum(weights)

    return weighted_sum / total_weight if total_weight > 0 else 0.0


def _confidence_score(
    daily_sold_qty: list[float], daily_rate: float, volatility: float
) -> int:
    """Lower confidence for short history (app_plan.md §36 "New") or highly
    volatile demand (coefficient of variation), matching §68's list of
    what should drag confidence down. Never claims more than 95% (this is a
    simple heuristic, not a validated model) or less than 10%.
    """
    mean = fmean(daily_sold_qty)
    coefficient_of_variation = (volatility / mean) if mean > 0 else 1.0

    score = 95.0 - min(70.0, coefficient_of_variation * 50.0)

    if len(daily_sold_qty) < MIN_RELIABLE_HISTORY_DAYS:
        score -= 25.0

    return max(10, min(95, round(score)))
