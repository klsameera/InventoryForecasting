"""Tests for the quantile -> expected-value conversion.

This encodes the bug that produced a wrong headline result: a quantile-loss
model's point output is the median, the median of ~71%-zero demand is zero, and
summing medians over a 30-day horizon scores WAPE exactly 1.0 / bias exactly
-1.0 while looking like catastrophic model failure.
"""

import numpy as np
import pytest

from training.prediction import expected_value_from_quantiles

LEVELS = [0.02, 0.1, 0.25, 0.5, 0.75, 0.9, 0.98]


def test_a_constant_distribution_returns_that_constant():
    """If every quantile is the same value, the mean is that value."""
    quantiles = np.full((1, 1, len(LEVELS)), 4.0)

    assert expected_value_from_quantiles(quantiles, LEVELS)[0, 0] == pytest.approx(4.0)


def test_a_zero_median_still_yields_a_positive_expectation():
    """The actual bug: median zero, upper tail positive.

    A model predicting median 0 with real mass in the upper quantiles has
    positive expected demand. Reading the median alone reports zero and throws
    that mass away.
    """
    quantiles = np.array([[[0.0, 0.0, 0.0, 0.0, 1.0, 3.0, 9.0]]])

    expected = expected_value_from_quantiles(quantiles, LEVELS)[0, 0]

    assert expected > 0.0
    # Sanity bound: cannot exceed the largest quantile.
    assert expected < 9.0


def test_output_drops_the_quantile_axis():
    quantiles = np.zeros((5, 30, len(LEVELS)))

    assert expected_value_from_quantiles(quantiles, LEVELS).shape == (5, 30)


def test_expectation_is_monotonic_in_the_distribution():
    """Shifting the whole distribution up must raise the expectation."""
    low = np.array([[[0.0, 0.0, 1.0, 2.0, 3.0, 4.0, 5.0]]])
    high = low + 2.0

    assert (
        expected_value_from_quantiles(high, LEVELS)[0, 0]
        - expected_value_from_quantiles(low, LEVELS)[0, 0]
    ) == pytest.approx(2.0)


def test_tails_are_held_flat_rather_than_extrapolated():
    """Below p=0.02 and above p=0.98 the model was never trained.

    Holding the outermost values flat is deliberate and conservative; the test
    pins it so a future change to linear extrapolation is a visible decision.
    """
    quantiles = np.array([[[1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 100.0]]])

    expected = expected_value_from_quantiles(quantiles, LEVELS)[0, 0]

    # Flat tail contributes 100 over p in [0.98, 1.0] -> 2.0, plus the
    # trapezoid from 0.9 to 0.98 averaging 1 and 100. Linear extrapolation past
    # p=0.98 would push this materially higher.
    assert expected == pytest.approx(1.0 * 0.9 + (1.0 + 100.0) / 2 * 0.08 + 100.0 * 0.02)


def test_a_symmetric_distribution_has_its_median_as_the_mean():
    quantiles = np.array([[[0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0]]])

    assert expected_value_from_quantiles(quantiles, LEVELS)[0, 0] == pytest.approx(
        3.0, abs=0.35
    )
