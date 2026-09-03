from fastapi.testclient import TestClient

from app.main import app, require_service_token
from app.config import Settings

client = TestClient(app)


def test_health_check():
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_forecast_run_returns_one_result_per_series():
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [
                {"warehouse_id": 1, "sku_id": 1, "daily_sold_qty": [5] * 30},
                {"warehouse_id": 1, "sku_id": 2, "daily_sold_qty": []},
            ],
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "completed"
    assert len(body["results"]) == 2
    assert body["results"][0]["sku_id"] == 1
    assert body["results"][0]["predicted_qty"] == 150.0
    assert body["results"][1]["predicted_qty"] == 0.0


def test_forecast_run_accepts_fractional_daily_sold_qty():
    # Real bug, found running the whole pipeline live: a Cold-start/Early
    # pair's series (DemandProfileService's peer-average, or
    # ForecastRunService::blend()'s own/fallback blend on the Laravel side)
    # is genuinely fractional, not a rounding artifact — this must not be
    # rejected as invalid input.
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [
                {"warehouse_id": 1, "sku_id": 1, "daily_sold_qty": [0.35, 0.7, 1.4, 0.0]},
            ],
        },
    )

    assert response.status_code == 200
    assert response.json()["results"][0]["predicted_qty"] > 0.0


def test_forecast_run_defaults_to_ewma_when_no_algorithm_is_given():
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 30,
            "series": [{"warehouse_id": 1, "sku_id": 1, "daily_sold_qty": [5] * 30}],
        },
    )

    assert response.status_code == 200
    assert response.json()["results"][0]["algorithm"] == "ewma"


def test_forecast_run_dispatches_seasonal_naive_when_requested():
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 7,
            "series": [
                {
                    "warehouse_id": 1,
                    "sku_id": 1,
                    "daily_sold_qty": [5] * 30,
                    "algorithm": "seasonal_naive",
                }
            ],
        },
    )

    assert response.status_code == 200
    result = response.json()["results"][0]
    assert result["algorithm"] == "seasonal_naive"
    # Flat 5/day history -> every weekday bucket averages 5, so a 7-day
    # horizon should predict exactly 35, the same as EWMA would for
    # perfectly flat demand.
    assert result["predicted_qty"] == 35.0


def test_forecast_run_rejects_an_unknown_algorithm():
    response = client.post(
        "/forecast/run",
        json={
            "horizon_days": 7,
            "series": [
                {
                    "warehouse_id": 1,
                    "sku_id": 1,
                    "daily_sold_qty": [5] * 30,
                    "algorithm": "random_forest",
                }
            ],
        },
    )

    assert response.status_code == 422


def test_forecast_run_rejects_an_out_of_range_horizon():
    response = client.post(
        "/forecast/run", json={"horizon_days": 0, "series": []}
    )

    assert response.status_code == 422


def test_auth_is_enforced_only_when_tokens_are_configured():
    open_settings = Settings(api_tokens=frozenset(), host="127.0.0.1", port=8090)
    require_service_token(authorization=None, settings=open_settings)  # no raise

    locked_settings = Settings(
        api_tokens=frozenset({"secret"}), host="127.0.0.1", port=8090
    )

    try:
        require_service_token(authorization=None, settings=locked_settings)
        assert False, "expected missing-token request to be rejected"
    except Exception as exc:
        assert getattr(exc, "status_code", None) == 401

    try:
        require_service_token(
            authorization="Bearer wrong", settings=locked_settings
        )
        assert False, "expected wrong-token request to be rejected"
    except Exception as exc:
        assert getattr(exc, "status_code", None) == 401

    require_service_token(
        authorization="Bearer secret", settings=locked_settings
    )  # no raise
