"""Tests for the trained-model serving path.

These deliberately do *not* load a checkpoint. A checkpoint is a build
artifact, not source: it may be absent on a fresh clone, and a test suite that
needs an hour of CPU to run first is a test suite nobody runs. What is tested
here is everything around the checkpoint — the refusal rules, the feature
assembly, and the guarantee that a refusal produces an honest baseline rather
than a wrong neural number.

`test_calendar_features_match_training` is the important one: it pins the
serving feature derivation against the training pipeline's own, which is the
single place where the two can silently drift apart and produce a model fed
inputs it was never trained on.
"""

from datetime import date

import pytest
from fastapi.testclient import TestClient

from app.forecasting import neural
from app.main import app
from app.schemas import NeuralFeatures, SeriesInput

client = TestClient(app)


def _features(**overrides) -> NeuralFeatures:
    defaults = {
        "series_start_date": date(2026, 1, 1),
        "category_id": 3,
        "brand_id": 7,
        "selling_price": 1500.0,
    }

    return NeuralFeatures(**{**defaults, **overrides})


def _series(**overrides) -> SeriesInput:
    defaults = {
        "warehouse_id": 1,
        "sku_id": 1,
        "daily_sold_qty": [3.0] * 120,
        "algorithm": "tft",
        "features": _features(),
    }

    return SeriesInput(**{**defaults, **overrides})


# --- padding -----------------------------------------------------------------


def test_short_covariate_is_padded_at_the_front():
    # Both series end on the same day - the forecast origin - so a covariate
    # that arrived short is missing its OLDEST values, not its newest. Padding
    # the back instead would attribute last week's stockout to last month.
    assert neural._padded([1.0, 2.0], 5) == [0.0, 0.0, 0.0, 1.0, 2.0]


def test_long_covariate_is_truncated_from_the_front():
    assert neural._padded([1.0, 2.0, 3.0, 4.0], 2) == [3.0, 4.0]


def test_empty_covariate_becomes_zeros():
    assert neural._padded([], 3) == [0.0, 0.0, 0.0]
    assert neural._padded(None, 2) == [0.0, 0.0]


# --- calendar features -------------------------------------------------------


def test_calendar_features_match_training():
    """The serving derivation must agree with `training/dataset.py`'s.

    They are separate implementations on purpose — the training module needs
    pandas, which a serving-only deployment does not install — so nothing but
    this test stops them drifting. A model trained on one definition of
    `is_payday` and served another gets a feature that means something else,
    with no error anywhere.
    """
    pandas = pytest.importorskip("pandas")

    from training.dataset import _add_calendar_features

    days = pandas.date_range("2025-01-01", "2026-12-31", freq="D")
    expected = _add_calendar_features(pandas.DataFrame({"date": days}))

    for position, timestamp in enumerate(days):
        actual = neural._calendar_features(timestamp.date())

        for column, value in actual.items():
            assert value == pytest.approx(expected[column].iloc[position]) or value == expected[column].iloc[position], (
                f"{column} disagrees on {timestamp.date()}: "
                f"serving={value} training={expected[column].iloc[position]}"
            )


def test_known_festival_windows_are_flagged():
    assert neural._calendar_features(date(2026, 4, 13))["is_avurudu"] == 1.0
    assert neural._calendar_features(date(2026, 5, 15))["is_vesak"] == 1.0
    assert neural._calendar_features(date(2026, 12, 20))["is_christmas"] == 1.0
    assert neural._calendar_features(date(2026, 7, 15))["is_avurudu"] == 0.0


def test_payday_covers_month_end_and_month_start():
    assert neural._calendar_features(date(2026, 1, 31))["is_payday"] == 1.0
    assert neural._calendar_features(date(2026, 2, 28))["is_payday"] == 1.0  # short month
    assert neural._calendar_features(date(2026, 1, 2))["is_payday"] == 1.0
    assert neural._calendar_features(date(2026, 1, 15))["is_payday"] == 0.0


# --- refusal rules -----------------------------------------------------------


class _FakeLoaded:
    """Stands in for a loaded checkpoint so the refusal rules can be tested
    without an hour of training first."""

    name = "tft"
    max_prediction_length = 30
    min_encoder_length = 45

    def known_categories(self, column):
        return {"1-1", "1-2", "1-3"}


def test_refuses_a_series_with_no_features():
    with pytest.raises(neural.UnsupportedSeries, match="features"):
        neural._check_servable(_FakeLoaded(), _series(features=None), 30)


def test_refuses_a_horizon_longer_than_the_decoder():
    with pytest.raises(neural.UnsupportedSeries, match="trained to predict 30 days"):
        neural._check_servable(_FakeLoaded(), _series(), 60)


def test_refuses_too_little_history():
    with pytest.raises(neural.UnsupportedSeries, match="at least 45 days"):
        neural._check_servable(_FakeLoaded(), _series(daily_sold_qty=[1.0] * 10), 30)


def test_refuses_a_sku_the_model_never_saw():
    with pytest.raises(neural.UnsupportedSeries, match="cold start"):
        neural._check_servable(_FakeLoaded(), _series(sku_id=999), 30)


def test_accepts_a_servable_series():
    neural._check_servable(_FakeLoaded(), _series(), 30)


def test_missing_checkpoint_raises_unsupported_not_a_crash():
    neural.reset_cache()

    with pytest.raises(neural.UnsupportedSeries):
        neural._load("no-such-model")


# --- refusal through the API -------------------------------------------------


def test_neural_request_without_features_is_refused_not_substituted():
    """A series the model cannot serve comes back as a refusal and nothing else.

    It used to come back as an EWMA number labelled `ewma`, which was honest
    bookkeeping but the wrong behaviour: asking for a trained model and
    receiving arithmetic is a surprise, and it let a run report success while
    holding results nobody requested. **No result is produced at all now.**
    """
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [
                {
                    "warehouse_id": 1,
                    "sku_id": 1,
                    "daily_sold_qty": [5] * 60,
                    "algorithm": "tft",
                }
            ],
        },
    )

    assert response.status_code == 200
    body = response.json()

    # Nothing was substituted: EWMA over a flat 5/day series would have been
    # 150.0, and that number must not appear anywhere in this response.
    assert body["results"] == []
    assert body["status"] == "partial"

    refusal = body["refusals"][0]
    assert refusal["algorithm"] == "tft"
    assert refusal["warehouse_id"] == 1
    assert refusal["sku_id"] == 1

    # The reason text depends on whether this checkout has a trained
    # checkpoint — without one the refusal comes from loading, with one it
    # comes from the missing feature block. Both are correct refusals, so this
    # asserts that a reason was recorded, not which one. The specific refusal
    # rules are pinned individually above against a stub model.
    assert refusal["reason"]


def test_a_baseline_request_still_answers_and_refuses_nothing():
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [{"warehouse_id": 1, "sku_id": 1, "daily_sold_qty": [5] * 60}],
        },
    )

    body = response.json()

    assert body["status"] == "completed"
    assert body["refusals"] == []
    assert body["results"][0]["algorithm"] == "ewma"
    assert body["results"][0]["predicted_qty"] == 150.0


def test_a_refused_series_does_not_take_the_servable_ones_with_it():
    """One unservable series must not cost the whole batch its answers.

    A run covers thousands of pairs and a handful may be too new for a trained
    model. Failing all of them over those few would be its own kind of wrong,
    so the servable series are still returned — beside an explicit refusal for
    the one that was not.
    """
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [
                {
                    "warehouse_id": 1,
                    "sku_id": 1,
                    "daily_sold_qty": [5] * 60,
                    "algorithm": "ewma",
                },
                {
                    "warehouse_id": 1,
                    "sku_id": 2,
                    "daily_sold_qty": [5] * 60,
                    "algorithm": "tft",
                },
            ],
        },
    )

    body = response.json()

    assert len(body["results"]) == 1
    assert body["results"][0]["sku_id"] == 1
    assert len(body["refusals"]) == 1
    assert body["refusals"][0]["sku_id"] == 2


def test_unknown_algorithm_is_rejected_by_validation():
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [
                {
                    "warehouse_id": 1,
                    "sku_id": 1,
                    "daily_sold_qty": [5] * 60,
                    "algorithm": "prophet",
                }
            ],
        },
    )

    assert response.status_code == 422


def test_a_mixed_batch_keeps_every_series_in_its_own_position():
    """Order preservation, which the batching refactor put at risk.

    Neural series are pulled out, run together, and written back by position.
    If that write-back drifted, SKUs would receive each other's forecasts — and
    every number would still look entirely plausible.
    """
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [
                {"warehouse_id": 1, "sku_id": 10, "daily_sold_qty": [2] * 60, "algorithm": "ewma"},
                {"warehouse_id": 1, "sku_id": 20, "daily_sold_qty": [5] * 60, "algorithm": "tft"},
                {"warehouse_id": 1, "sku_id": 30, "daily_sold_qty": [9] * 60, "algorithm": "seasonal_naive"},
                {"warehouse_id": 1, "sku_id": 40, "daily_sold_qty": [1] * 60, "algorithm": "tft"},
            ],
        },
    )

    assert response.status_code == 200
    body = response.json()
    results = body["results"]

    # The two tft rows are refused (no features), so only the baselines answer —
    # and they keep their own order and their own numbers. Position is what this
    # test guards: a drift in the write-back would hand SKU 10 SKU 30's figure,
    # and both would still look entirely plausible.
    assert [r["sku_id"] for r in results] == [10, 30]
    assert results[0]["predicted_qty"] == 60.0     # 2/day x 30
    assert results[1]["predicted_qty"] == 270.0    # 9/day x 30

    # The refused pair is reported in its own order, and nothing was invented
    # for either: 150.0 and 30.0 are what EWMA would have substituted.
    assert [r["sku_id"] for r in body["refusals"]] == [20, 40]
    assert all(r["algorithm"] == "tft" for r in body["refusals"])


def test_a_duplicated_pair_in_one_batch_is_refused_not_silently_merged():
    # Two entries for the same warehouse/SKU would collide in the model's group
    # index and both receive whichever prediction landed last.
    accepted, refusals = neural.plan_batch(_FakeLoaded(), [_series(), _series()], 30)

    assert accepted == {"1-1": 0}
    assert "more than once" in str(refusals[1])


def test_planning_keeps_servable_series_and_records_each_refusal():
    # Distinct SKUs throughout: two entries for the same pair are refused as
    # duplicates before servability is even considered, which would mask the
    # reasons this test is about.
    batch = [
        _series(),                                              # servable
        _series(sku_id=999),                                    # unknown to the encoder
        _series(sku_id=2, daily_sold_qty=[1.0] * 5),            # too little history
        _series(sku_id=3, features=None),                       # no covariates
    ]

    accepted, refusals = neural.plan_batch(_FakeLoaded(), batch, 30)

    assert accepted == {"1-1": 0}
    assert set(refusals) == {1, 2, 3}
    assert "cold start" in str(refusals[1])
    assert "at least 45 days" in str(refusals[2])
    assert "features" in str(refusals[3])


def test_a_missing_checkpoint_refuses_every_series_in_the_batch():
    neural.reset_cache()

    outcomes = neural.forecast_many([_series(), _series()], 30, algorithm="no-such-model")

    assert len(outcomes) == 2
    assert all(isinstance(outcome, neural.UnsupportedSeries) for outcome in outcomes)


def test_forecast_many_returns_refusals_rather_than_raising(monkeypatch):
    """One unservable SKU must not cost the rest of the batch its forecast.

    Both series here are unservable, so no checkpoint is ever touched — the
    point is that `forecast_many` *returns* the refusals in position rather
    than raising the first one and losing the batch.
    """
    monkeypatch.setattr(neural, "_load", lambda name: _FakeLoaded())

    outcomes = neural.forecast_many(
        [_series(sku_id=999), _series(daily_sold_qty=[1.0] * 5)], 30, algorithm="tft"
    )

    assert len(outcomes) == 2
    assert "cold start" in str(outcomes[0])
    assert "at least 45 days" in str(outcomes[1])


def test_models_endpoint_reports_every_algorithm():
    response = client.get("/models")

    assert response.status_code == 200
    algorithms = response.json()["algorithms"]

    assert algorithms["ewma"]["available"] is True
    assert algorithms["seasonal_naive"]["available"] is True
    # tft/deepar depend on whether this checkout has trained checkpoints, so
    # only the shape of the answer is asserted, never its value.
    assert set(algorithms) == {"ewma", "seasonal_naive", "tft", "deepar"}
    assert "available" in algorithms["tft"]
