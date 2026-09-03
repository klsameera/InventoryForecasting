"""Regression tests for seasonal_naive's `as_of` parameter.

`as_of` was added so the model can be backtested from a historical cutoff
(training/evaluate.py). Before it existed, every weekday was resolved against
`date.today()`, which silently misaligned the whole weekly pattern whenever
the scored window was not the present one - making the model look far worse
than it is and, worse, doing so without any error.
"""

from datetime import date, timedelta

import pytest

from app.forecasting.seasonal_naive import forecast


def _weekly_series(weeks: int, pattern: list[float], ending: date) -> list[float]:
    """Build a series whose weekday pattern is exact and known.

    `pattern` is indexed by Python weekday (0=Monday). The returned list is
    oldest-first and ends on `ending`.
    """
    days = weeks * 7
    start = ending - timedelta(days=days - 1)

    return [pattern[(start + timedelta(days=offset)).weekday()] for offset in range(days)]


def test_omitting_as_of_preserves_the_original_today_based_behaviour():
    series = _weekly_series(8, [1, 1, 1, 1, 1, 1, 1], date.today() - timedelta(days=1))

    assert forecast(series, 7).predicted_qty == pytest.approx(
        forecast(series, 7, as_of=date.today()).predicted_qty
    )


def test_a_known_weekday_pattern_is_recovered_exactly():
    """Saturdays sell 10, every other day sells 1.

    Over a 7-day horizon the model should predict 6*1 + 10 = 16 regardless of
    which weekday the horizon opens on, because it covers exactly one of each.
    """
    pattern = [1, 1, 1, 1, 1, 10, 1]  # index 5 == Saturday
    as_of = date(2025, 6, 2)  # a Monday

    series = _weekly_series(8, pattern, as_of - timedelta(days=1))

    assert forecast(series, 7, as_of=as_of).predicted_qty == pytest.approx(16.0)


def test_a_short_horizon_reflects_which_weekdays_it_actually_covers():
    """The whole point of the model: two-day horizons differ by weekday."""
    pattern = [1, 1, 1, 1, 1, 10, 1]
    monday = date(2025, 6, 2)
    saturday = date(2025, 6, 7)

    from_monday = forecast(
        _weekly_series(8, pattern, monday - timedelta(days=1)), 2, as_of=monday
    )
    from_saturday = forecast(
        _weekly_series(8, pattern, saturday - timedelta(days=1)), 2, as_of=saturday
    )

    assert from_monday.predicted_qty == pytest.approx(2.0)   # Mon + Tue
    assert from_saturday.predicted_qty == pytest.approx(11.0)  # Sat + Sun


def test_as_of_does_not_change_the_prediction():
    """`as_of` is inert for `predicted_qty`, and that is correct.

    **Rewritten 2026-09-02, and the finding is worth reading.** The test that
    stood here asserted that scoring with an explicit historical `as_of`
    differs from scoring against today, guarded by "skip if today is a
    Saturday". Two things were wrong with it. It only ever ran on non-Saturdays
    — and 2026-08-22, the last day this suite was run before today, was a
    Saturday, so the assertion was skipped and the suite reported green.
    And the assertion cannot hold on any day, because the function is
    *translation-invariant*: `_weekday_averages` labels the history backwards
    from `as_of - 1` and the horizon is read forwards from `as_of`, so shifting
    `as_of` moves the weekday buckets and the days being forecast by exactly
    the same amount and the two cancel.

    That invariance is the right behaviour, not a bug. An element's weekday is
    fixed by its offset from the end of the series, and so is each forecast
    day's; nothing else is knowable, because the function receives a bare list
    of numbers with no dates attached. What `as_of` genuinely buys is
    *readability* at the call site in `training/evaluate.py` — it does not
    change a single output value, and the docstring on
    `seasonal_naive.forecast` claiming a wrong `as_of` "makes the model look
    far worse than it is" overstates it.

    Asserted across a full week so any future change that makes `as_of`
    actually load-bearing fails here loudly instead of quietly shifting every
    recorded backtest number.
    """
    pattern = [1, 1, 1, 1, 1, 10, 1]
    anchor = date(2024, 6, 8)

    series = _weekly_series(8, pattern, anchor - timedelta(days=1))
    baseline = forecast(series, 3, as_of=anchor).predicted_qty

    for offset in range(7):
        assert forecast(
            series, 3, as_of=anchor + timedelta(days=offset)
        ).predicted_qty == pytest.approx(baseline)


def test_omitting_as_of_is_exactly_as_of_today():
    """The documented default, asserted directly rather than inferred.

    The half of the old test that was worth keeping: `as_of=None` must mean
    `date.today()` and nothing else, so live serving keeps its behaviour.
    """
    pattern = [1, 1, 1, 1, 1, 10, 1]
    series = _weekly_series(8, pattern, date.today() - timedelta(days=1))

    assert forecast(series, 3).predicted_qty == pytest.approx(
        forecast(series, 3, as_of=date.today()).predicted_qty
    )
