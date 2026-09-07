"""FastAPI entrypoint. See README.md for what this service serves and how to
run it alongside the Laravel app.
"""

import logging

from fastapi import Depends, FastAPI, Header, HTTPException, status

from app.config import Settings, settings as default_settings
from app.forecasting import neural, seasonal_naive
from app.forecasting.baseline import BaselineForecast, forecast as run_baseline_forecast
from app.schemas import (
    NEURAL_ALGORITHMS,
    ForecastRequest,
    ForecastResponse,
    ForecastResult,
    SeriesInput,
    SeriesRefusal,
)

logger = logging.getLogger(__name__)

app = FastAPI(
    title="InventoryForecasting ML service",
    description=(
        "Demand forecasting for the InventoryForecasting app. Serves two "
        "statistical baselines (app/forecasting/baseline.py, "
        "app/forecasting/seasonal_naive.py) and two trained neural models "
        "(app/forecasting/neural.py). Laravel selects per SKU from scored "
        "accuracy history — app_plan.md §86."
    ),
    version="0.2.0",
)

def _run_baseline(series: SeriesInput, horizon_days: int) -> BaselineForecast:
    return run_baseline_forecast(series.daily_sold_qty, horizon_days)


def _run_seasonal_naive(series: SeriesInput, horizon_days: int) -> BaselineForecast:
    return seasonal_naive.forecast(series.daily_sold_qty, horizon_days)


_BASELINES = {
    "ewma": _run_baseline,
    "seasonal_naive": _run_seasonal_naive,
}


def _result(
    series: SeriesInput,
    outcome: BaselineForecast,
    algorithm: str,
) -> ForecastResult:
    return ForecastResult(
        warehouse_id=series.warehouse_id,
        sku_id=series.sku_id,
        algorithm=algorithm,
        **outcome.__dict__,
    )


def _refusal(series: SeriesInput, algorithm: str, reason: str) -> SeriesRefusal:
    """Report that the requested algorithm declined this series.

    **Nothing is substituted.** A neural model refuses a series it cannot
    honestly serve — an unknown SKU, too little history, a horizon longer than
    its decoder, a missing checkpoint — and that refusal is returned as itself
    rather than quietly answered by EWMA.

    The substitution it replaces was recorded accurately, but accurate
    bookkeeping is not the same as the right behaviour: asking for one model and
    receiving another model's number is a surprise, and a run that reports
    success while containing results nobody requested hides that surprise. An
    operator who wanted a trained model would rather be told it could not run.
    """
    logger.info(
        "%s declined warehouse=%s sku=%s: %s",
        algorithm,
        series.warehouse_id,
        series.sku_id,
        reason,
    )

    return SeriesRefusal(
        warehouse_id=series.warehouse_id,
        sku_id=series.sku_id,
        algorithm=algorithm,
        reason=reason,
    )


def _run_batch(
    payload: ForecastRequest,
) -> tuple[list[ForecastResult], list[SeriesRefusal]]:
    """Run every series, grouping the neural ones so each model runs **once**.

    The baselines are per-series arithmetic and cost nothing to loop over. A
    trained model is the opposite: each `predict()` call builds a trainer and a
    dataloader, so running one per series turned a 441-pair batch into ~880 of
    them and exceeded the client's HTTP timeout. Series requesting the same
    trained model therefore go through `neural.forecast_many` together, in one
    forward pass, and results are written back into their original positions.
    """
    results: list[ForecastResult | None] = [None] * len(payload.series)
    refusals: list[SeriesRefusal] = []
    neural_batches: dict[str, list[int]] = {}

    for position, series in enumerate(payload.series):
        if series.algorithm in NEURAL_ALGORITHMS:
            neural_batches.setdefault(series.algorithm, []).append(position)
            continue

        results[position] = _result(
            series,
            _BASELINES[series.algorithm](series, payload.horizon_days),
            series.algorithm,
        )

    for algorithm, positions in neural_batches.items():
        batch = [payload.series[position] for position in positions]
        outcomes = neural.forecast_many(batch, payload.horizon_days, algorithm=algorithm)

        for position, series, outcome in zip(positions, batch, outcomes):
            if isinstance(outcome, neural.UnsupportedSeries):
                refusals.append(_refusal(series, algorithm, str(outcome)))

                continue

            results[position] = _result(series, outcome, algorithm)

    return [result for result in results if result is not None], refusals


def get_settings() -> Settings:
    return default_settings


def require_service_token(
    authorization: str | None = Header(default=None),
    settings: Settings = Depends(get_settings),
) -> None:
    """app_plan.md §74: service-to-service authentication. A no-op when
    ML_SERVICE_API_TOKENS is unset (local development); once any token is
    configured, every request except /health must present one.

    `settings` is dependency-injected (rather than read from the module-level
    singleton directly) so tests can exercise both the open and locked-down
    cases without mutating global state.
    """
    if not settings.api_tokens:
        return

    if authorization is None or not authorization.startswith("Bearer "):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Missing bearer token")

    token = authorization.removeprefix("Bearer ").strip()

    if token not in settings.api_tokens:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Invalid service token")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/models")
def models() -> dict[str, object]:
    """Which algorithms this deployment can actually serve.

    Laravel calls this before offering a neural algorithm as a candidate, so a
    service running without checkpoints is never asked for one. Reports load
    failures rather than hiding them — a missing checkpoint and a broken one
    should not look the same to an operator.
    """
    available = {"ewma": {"trained": False}, "seasonal_naive": {"trained": False}}

    for name in NEURAL_ALGORITHMS:
        try:
            loaded = neural._load(name)
        except neural.UnsupportedSeries as exc:
            available[name] = {"trained": True, "available": False, "reason": str(exc)}
            continue

        available[name] = {
            "trained": True,
            "available": True,
            "trained_through": loaded.last_training_date.isoformat(),
            "max_horizon_days": loaded.max_prediction_length,
            "min_history_days": loaded.min_encoder_length,
        }

    for name in ("ewma", "seasonal_naive"):
        available[name]["available"] = True

    return {"algorithms": available}


@app.post(
    "/forecast/run",
    response_model=ForecastResponse,
    dependencies=[Depends(require_service_token)],
)
def run_forecast(payload: ForecastRequest) -> ForecastResponse:
    results, refusals = _run_batch(payload)

    return ForecastResponse(
        status="completed" if not refusals else "partial",
        results=results,
        refusals=refusals,
    )
