import math

import numpy as np
import pytest

from training import metrics


def test_perfect_forecast_scores_zero_error():
    actual = np.array([3.0, 0.0, 5.0, 2.0])

    scores = metrics.score(actual, actual.copy())

    assert scores.wape == 0.0
    assert scores.mae == 0.0
    assert scores.bias == 0.0


def test_wape_is_total_absolute_error_over_total_actual():
    actual = np.array([10.0, 10.0])
    predicted = np.array([8.0, 15.0])

    scores = metrics.score(actual, predicted)

    # |10-8| + |10-15| = 7 over an actual total of 20.
    assert scores.wape == pytest.approx(0.35)
    assert scores.mae == pytest.approx(3.5)


def test_bias_is_signed_and_separates_over_from_under_forecasting():
    actual = np.array([10.0, 10.0])

    over = metrics.score(actual, np.array([13.0, 13.0]))
    under = metrics.score(actual, np.array([7.0, 7.0]))

    assert over.bias == pytest.approx(0.3)
    assert under.bias == pytest.approx(-0.3)

    # The point of reporting bias alongside WAPE: these two models are equally
    # inaccurate but fail in opposite directions, and WAPE alone cannot tell
    # them apart.
    assert over.wape == pytest.approx(under.wape)


def test_all_zero_actuals_give_nan_rather_than_a_flattering_zero():
    """A window where nothing sold has no meaningful percentage error.

    Returning 0.0 here would make a do-nothing model look perfect on every
    dead SKU, and this dataset is ~71% zero-demand days.
    """
    scores = metrics.score(np.zeros(5), np.array([1.0, 0.0, 0.0, 2.0, 0.0]))

    assert math.isnan(scores.wape)
    assert math.isnan(scores.bias)
    # MAE is still defined and still reports the spurious units.
    assert scores.mae == pytest.approx(0.6)


def test_predicting_all_zeros_against_real_demand_scores_wape_one():
    """The trivial-collapse case, which intermittent data invites.

    A model that gives up and predicts zero everywhere must score WAPE 1.0 and
    bias -1.0, so the collapse is visible rather than looking like a good MAE.
    """
    scores = metrics.score(np.array([2.0, 0.0, 4.0]), np.zeros(3))

    assert scores.wape == pytest.approx(1.0)
    assert scores.bias == pytest.approx(-1.0)


def test_shape_mismatch_is_rejected():
    with pytest.raises(ValueError, match="shape mismatch"):
        metrics.score(np.zeros(4), np.zeros(3))


def test_empty_input_is_rejected():
    with pytest.raises(ValueError, match="empty"):
        metrics.score(np.array([]), np.array([]))


def test_two_dimensional_input_is_flattened_consistently():
    """Per-day scoring passes (n_series, horizon) arrays."""
    actual = np.array([[1.0, 2.0], [3.0, 4.0]])
    predicted = np.array([[1.0, 2.0], [3.0, 0.0]])

    scores = metrics.score(actual, predicted)

    assert scores.n == 4
    assert scores.wape == pytest.approx(4.0 / 10.0)
