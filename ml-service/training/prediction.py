"""Turning a model's raw output into the number you actually score.

Shared by `train.py` and `evaluate.py` so both report the same quantity. They
previously did not: `train.py` scored the raw point output while `evaluate.py`
converted quantiles to an expected value, which meant the same checkpoint could
be reported with two very different WAPEs depending on which script printed it.
"""

from __future__ import annotations

import numpy as np


def is_quantile_loss(model) -> bool:
    """Whether this model's point output is a quantile (the median) not a mean."""
    from pytorch_forecasting.metrics import QuantileLoss

    return isinstance(model.loss, QuantileLoss)


def expected_value_from_quantiles(quantiles: np.ndarray, levels) -> np.ndarray:
    """Approximate E[X] by integrating the quantile function over [0, 1].

    Why this is needed at all: a quantile-loss model's natural point output is
    the median, and for intermittent demand the median is usually zero - about
    71% of days in this dataset have none. Summing medians across a 30-day
    horizon predicts zero for almost every SKU, which scores a WAPE of exactly
    1.0 and a bias of exactly -1.0. That is not the model failing; it is the
    wrong statistic. An inventory decision needs *expected* demand.

    E[X] = the integral of Q(p) dp, approximated by the trapezoid rule over the
    trained quantile levels, holding the outermost values flat out to p=0 and
    p=1 - a conservative treatment of tails the model was never trained on.

    A model trained with a count distribution loss (negative binomial) already
    produces a mean and must NOT be passed through this; see is_quantile_loss.
    """
    levels = np.asarray(levels, dtype=np.float64)

    padded_levels = np.concatenate(([0.0], levels, [1.0]))
    padded_values = np.concatenate(
        (quantiles[..., :1], quantiles, quantiles[..., -1:]), axis=-1
    )

    return np.trapz(padded_values, padded_levels, axis=-1)


def point_forecast(model, dataset_or_loader, *, trainer_kwargs=None, return_index=False):
    """Predict, converting a quantile model's median output to an expected value.

    Deliberately avoids `return_y=True`: pytorch-forecasting accumulates y
    across batches and concatenates along dim=1, which raises on a ragged final
    batch ("Expected size 64 but got size 15"). Callers read actuals from the
    source frame instead.
    """
    quantile = is_quantile_loss(model)

    raw = model.predict(
        dataset_or_loader,
        mode="quantiles" if quantile else "prediction",
        return_index=return_index,
        trainer_kwargs=trainer_kwargs or {"accelerator": "cpu", "enable_progress_bar": False},
    )

    output = raw.output if return_index else raw
    output = output.cpu().numpy() if hasattr(output, "cpu") else np.asarray(output)

    if quantile:
        output = expected_value_from_quantiles(output, model.loss.quantiles)

    output = np.clip(output, 0, None)

    return (output, raw.index) if return_index else output
