"""Forecast accuracy metrics.

Why these three, and why not MAPE
---------------------------------
Laravel's `ModelSelectionService` currently compares algorithms on
`forecast_accuracy.percentage_error`, a MAPE-style measure. MAPE is undefined
when actual demand is zero and explodes when it is near zero — and roughly
seven in ten days in this dataset have zero demand. Ranking models on MAPE
over intermittent retail demand ranks them mostly on how they behave in the
undefined case, which is why the metrics below are used instead:

* **WAPE** — total absolute error over total actual demand. Defined whenever a
  series sold anything at all over the window, weights busy days above quiet
  ones, and is the standard choice for intermittent demand. This is the
  headline number for model selection.
* **MAE** — mean absolute error in units. Not scale-free, so it cannot rank
  across SKUs, but it is the number a planner can reason about directly.
* **Bias** — signed error over total actual. Distinguishes a model that is
  wrong in both directions from one that systematically over- or
  under-forecasts. A model with good WAPE and bad bias will quietly build up
  either stockouts or dead stock, which WAPE alone will not reveal.
"""

from __future__ import annotations

from dataclasses import dataclass, asdict

import numpy as np


@dataclass(frozen=True)
class Scores:
    wape: float
    mae: float
    bias: float
    actual_total: float
    predicted_total: float
    n: int

    def as_dict(self) -> dict[str, float | int]:
        return asdict(self)

    def __str__(self) -> str:
        return (
            f"WAPE {self.wape:7.4f}   MAE {self.mae:7.4f}   "
            f"Bias {self.bias:+7.4f}   (n={self.n}, actual={self.actual_total:.0f}, "
            f"predicted={self.predicted_total:.0f})"
        )


def score(actual: np.ndarray, predicted: np.ndarray) -> Scores:
    """Score a flat array of predictions against actuals."""
    actual = np.asarray(actual, dtype=np.float64).ravel()
    predicted = np.asarray(predicted, dtype=np.float64).ravel()

    if actual.shape != predicted.shape:
        raise ValueError(f"shape mismatch: actual {actual.shape} vs predicted {predicted.shape}")

    if actual.size == 0:
        raise ValueError("cannot score an empty array")

    absolute_error = np.abs(actual - predicted)
    actual_total = float(actual.sum())

    # A window where nothing sold at all has no meaningful percentage-based
    # error. Reported as NaN rather than 0.0 or infinity, so it is visibly
    # excluded from any comparison instead of silently flattering a model.
    wape = float(absolute_error.sum() / actual_total) if actual_total > 0 else float("nan")
    bias = float((predicted - actual).sum() / actual_total) if actual_total > 0 else float("nan")

    return Scores(
        wape=wape,
        mae=float(absolute_error.mean()),
        bias=bias,
        actual_total=actual_total,
        predicted_total=float(predicted.sum()),
        n=int(actual.size),
    )
