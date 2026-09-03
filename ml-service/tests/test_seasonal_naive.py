from datetime import date, timedelta

from app.forecasting.seasonal_naive import forecast


def _build_weekly_pattern_history(
    weeks: int, special_weekday: int, special_qty: int, normal_qty: int
) -> list[int]:
    """Builds `weeks` full weeks of history ending yesterday, where
    `special_weekday` (0=Monday..6=Sunday) always sells `special_qty` and
    every other day sells `normal_qty`.
    """
    days = weeks * 7
    yesterday = date.today() - timedelta(days=1)
    history = []

    for index in range(days):
        day = yesterday - timedelta(days=(days - 1 - index))
        history.append(
            special_qty if day.weekday() == special_weekday else normal_qty
        )

    return history


def test_no_history_returns_zero_with_low_confidence():
    result = forecast(daily_sold_qty=[], horizon_days=30)

    assert result.predicted_qty == 0.0
    assert result.confidence_score == 10


def test_all_zero_history_returns_zero_with_low_confidence():
    result = forecast(daily_sold_qty=[0] * 30, horizon_days=30)

    assert result.predicted_qty == 0.0
    assert result.confidence_score == 10


def test_a_full_week_horizon_reflects_the_weekly_pattern_regardless_of_todays_weekday():
    # Sunday always sells 20, every other day sells 5, over 8 weeks.
    history = _build_weekly_pattern_history(
        weeks=8, special_weekday=6, special_qty=20, normal_qty=5
    )

    result = forecast(daily_sold_qty=history, horizon_days=7)

    # A 7-day horizon always contains exactly one of each weekday, so this
    # is deterministic regardless of which real weekday "today" is.
    assert result.predicted_qty == 50.0  # 6 * 5 + 20


def test_single_day_of_history_still_covers_every_weekday_via_the_overall_mean_fallback():
    # Only one real day of history — six of the seven weekdays have no
    # data at all and must fall back to the overall mean (10 here, since
    # there's only one observation), never a fabricated zero.
    result = forecast(daily_sold_qty=[10], horizon_days=7)

    assert result.predicted_qty == 70.0


def test_confidence_never_exceeds_95_or_drops_below_10():
    history = _build_weekly_pattern_history(
        weeks=52, special_weekday=6, special_qty=20, normal_qty=5
    )

    result = forecast(daily_sold_qty=history, horizon_days=7)

    assert 10 <= result.confidence_score <= 95


def test_short_history_is_penalized_even_if_perfectly_stable():
    short = _build_weekly_pattern_history(
        weeks=1, special_weekday=6, special_qty=5, normal_qty=5
    )
    long = _build_weekly_pattern_history(
        weeks=52, special_weekday=6, special_qty=5, normal_qty=5
    )

    result_short = forecast(daily_sold_qty=short, horizon_days=7)
    result_long = forecast(daily_sold_qty=long, horizon_days=7)

    assert result_short.confidence_score < result_long.confidence_score


def test_a_pair_with_a_strong_weekly_pattern_has_a_tighter_interval_than_pure_noise():
    seasonal = _build_weekly_pattern_history(
        weeks=12, special_weekday=6, special_qty=20, normal_qty=5
    )
    noisy = [0, 10, 0, 10, 0, 10, 0, 10] * 10

    seasonal_result = forecast(daily_sold_qty=seasonal, horizon_days=7)
    noisy_result = forecast(daily_sold_qty=noisy, horizon_days=7)

    seasonal_width = seasonal_result.upper_qty - seasonal_result.lower_qty
    noisy_width = noisy_result.upper_qty - noisy_result.lower_qty

    assert seasonal_width < noisy_width
