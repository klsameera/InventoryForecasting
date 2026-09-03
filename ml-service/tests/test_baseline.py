from app.forecasting.baseline import forecast


def test_no_history_returns_zero_with_low_confidence():
    result = forecast(daily_sold_qty=[], horizon_days=30)

    assert result.predicted_qty == 0.0
    assert result.lower_qty == 0.0
    assert result.upper_qty == 0.0
    assert result.confidence_score == 10


def test_all_zero_history_returns_zero_with_low_confidence():
    result = forecast(daily_sold_qty=[0] * 30, horizon_days=30)

    assert result.predicted_qty == 0.0
    assert result.confidence_score == 10


def test_steady_demand_predicts_close_to_the_daily_average_times_horizon():
    # Perfectly steady demand: 5 units/day for 60 days.
    result = forecast(daily_sold_qty=[5] * 60, horizon_days=30)

    assert result.predicted_qty == 150.0
    # No volatility at all -> the interval collapses to the point estimate.
    assert result.lower_qty == 150.0
    assert result.upper_qty == 150.0
    # Long, stable history -> high confidence.
    assert result.confidence_score >= 90


def test_recent_days_are_weighted_more_than_older_ones():
    # A step change from 0/day to 10/day partway through recent history.
    ramping_up = [0] * 30 + [10] * 30
    ramping_down = [10] * 30 + [0] * 30

    up = forecast(daily_sold_qty=ramping_up, horizon_days=30)
    down = forecast(daily_sold_qty=ramping_down, horizon_days=30)

    # The series that ends high should predict higher than the one that ends low.
    assert up.predicted_qty > down.predicted_qty


def test_volatile_demand_has_a_wider_interval_and_lower_confidence_than_steady_demand():
    steady = forecast(daily_sold_qty=[5] * 60, horizon_days=30)
    volatile = forecast(
        daily_sold_qty=[0, 10, 0, 10, 0, 10, 0, 10] * 8, horizon_days=30
    )

    assert (volatile.upper_qty - volatile.lower_qty) > (
        steady.upper_qty - steady.lower_qty
    )
    assert volatile.confidence_score < steady.confidence_score


def test_short_history_is_penalized_even_if_stable():
    short = forecast(daily_sold_qty=[5] * 5, horizon_days=30)
    long = forecast(daily_sold_qty=[5] * 60, horizon_days=30)

    assert short.confidence_score < long.confidence_score


def test_confidence_never_exceeds_95_or_drops_below_10():
    perfectly_steady_long_history = forecast(
        daily_sold_qty=[5] * 365, horizon_days=7
    )

    assert 10 <= perfectly_steady_long_history.confidence_score <= 95
