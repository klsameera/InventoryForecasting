"""Request/response contract for POST /forecast/run. Mirrors what
MlServiceClient (Laravel side, domain/Services/MlServiceClient/) sends and
expects back — the two must be changed together.
"""

from datetime import date
from typing import Literal

from pydantic import BaseModel, Field

Algorithm = Literal["ewma", "seasonal_naive", "tft", "deepar"]

# The subset of Algorithm that resolves to a trained checkpoint rather than a
# formula. These require `SeriesInput.features`; the statistical two ignore it.
NEURAL_ALGORITHMS = ("tft", "deepar")


class NeuralFeatures(BaseModel):
    """The covariates a trained model needs and the baselines do not.

    Kept as a separate optional block rather than flattened onto SeriesInput
    for one reason: it is *all or nothing*. A neural forecast built from a
    partially-filled feature set is not a slightly worse forecast, it is a
    model told that this SKU costs nothing, belongs to no category and was
    never out of stock. Grouping the fields makes "did the caller supply the
    features?" a single check instead of nine, and keeps the baselines'
    request shape exactly as it was.

    Every daily list is parallel to `daily_sold_qty` and ends on the same day.
    Shorter lists are front-padded with zeros (see `neural.py::_padded`), never
    back-padded, because the series are anchored at their *end*.
    """

    # The calendar date of daily_sold_qty[0]. Without it there is no way to
    # resolve the series onto the training frame's time_idx origin, or to know
    # which weekday any historical value fell on.
    series_start_date: date

    category_id: int | None = None
    brand_id: int | None = None
    selling_price: float = 0.0

    # Past-observed covariates — knowable only in hindsight, so they cover the
    # history window only. The TFT reads these; DeepAR structurally cannot.
    available_qty: list[float] = Field(default_factory=list)
    received_qty: list[float] = Field(default_factory=list)
    stockout_minutes: list[float] = Field(default_factory=list)

    # Known-future covariates — supplied for the history window and, separately,
    # for the forecast horizon, because a retailer genuinely knows its own
    # promotion calendar in advance.
    on_promotion: list[float] = Field(default_factory=list)
    promotion_discount: list[float] = Field(default_factory=list)
    future_on_promotion: list[float] = Field(default_factory=list)
    future_promotion_discount: list[float] = Field(default_factory=list)


class SeriesInput(BaseModel):
    """One warehouse/SKU's daily-sold-quantity history, oldest first.

    `algorithm` (Phase 10, app_plan.md §86 "automatic model selection")
    lets each series in the same batch request a different strategy —
    Laravel decides per SKU, from real backtested `forecast_accuracy`
    history, which algorithm this service should run; this service has no
    opinion of its own and simply dispatches on whatever it's told. Defaults
    to "ewma" so an older/unaware caller keeps today's behavior unchanged.

    A neural `algorithm` additionally requires `features`. If it is missing, or
    the model cannot serve this series for any other reason, the service falls
    back to `ewma` and says so in `ForecastResult.algorithm` — it never returns
    a baseline number under a neural model's name.
    """

    warehouse_id: int
    sku_id: int
    # A pair's own recorded sales are always whole units, but a Cold-start
    # or Early pair's series may be a peer-averaged or own/fallback-blended
    # value (DemandProfileService / ForecastRunService::blend() on the
    # Laravel side) — genuinely fractional, not a rounding artifact. `float`
    # accepts both; narrowing this back to `int` would silently discard
    # real signal for exactly the SKUs with the least data to spare.
    daily_sold_qty: list[float] = Field(default_factory=list)
    algorithm: Algorithm = "ewma"
    features: NeuralFeatures | None = None


class ForecastRequest(BaseModel):
    horizon_days: int = Field(gt=0, le=365)
    series: list[SeriesInput]


class ForecastResult(BaseModel):
    warehouse_id: int
    sku_id: int
    predicted_qty: float
    lower_qty: float
    upper_qty: float
    confidence_score: int = Field(ge=0, le=100)
    forecast_source: str = "SKU_HISTORY"

    # The algorithm that ran. It is now always the one that was requested:
    # nothing is ever silently substituted, so a result under this name really
    # came from this algorithm.
    algorithm: Algorithm = "ewma"


class SeriesRefusal(BaseModel):
    """A series the requested algorithm declined, and why.

    **No prediction accompanies it, deliberately.** The service used to answer a
    refusal with EWMA and echo which algorithm it really ran. That was honest
    about its own substitution, but it meant asking for one model and receiving
    a number from another — so a run could report success while quietly
    containing results nobody asked for.

    A refusal is now returned as itself. The caller decides what to do about it,
    and the operator is told rather than served a stand-in.
    """

    warehouse_id: int
    sku_id: int
    algorithm: Algorithm
    reason: str


class ForecastResponse(BaseModel):
    status: str = "completed"
    results: list[ForecastResult]

    # Series the requested algorithm could not serve. Empty on a clean run.
    refusals: list[SeriesRefusal] = []
