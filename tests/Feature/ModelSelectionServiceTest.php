<?php

use App\Models\Forecast;
use App\Models\ForecastAccuracy;
use App\Models\MlModelVersion;
use App\Models\Sku;
use Domain\Facades\ModelSelectionFacade\ModelSelectionFacade;

/*
 * These two pin the *unconfigured* default, so they set it explicitly.
 *
 * They previously did not, and read whatever `ML_DEFAULT_ALGORITHM` the
 * developer had in `.env` — which made the suite pass or fail depending on
 * local configuration rather than on the code. Setting the trained model as the
 * local default is what exposed it. The behaviour they describe is still real;
 * it is just no longer hardcoded, so the test has to say which configuration it
 * is asserting about.
 */
test('falls back to the configured default when a sku has no accuracy history for any algorithm', function () {
    config(['services.ml.default_algorithm' => 'ewma']);

    $sku = Sku::factory()->create();

    expect(ModelSelectionFacade::chooseAlgorithm($sku->id))->toBe('ewma');
});

test('falls back to the configured default when only one algorithm has been scored for a sku', function () {
    config(['services.ml.default_algorithm' => 'ewma']);

    $sku = Sku::factory()->create();
    $ewmaVersion = MlModelVersion::factory()->create(['name' => 'baseline-moving-average', 'version' => 'v1']);

    $forecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $ewmaVersion->id]);
    ForecastAccuracy::factory()->create(['forecast_id' => $forecast->id, 'percentage_error' => 10.0]);

    // Only ewma has ever been scored for this SKU — there's nothing real to
    // compare it against yet, so this is not a genuine comparison.
    expect(ModelSelectionFacade::chooseAlgorithm($sku->id))->toBe('ewma');
});

test('picks whichever algorithm has scored a lower average percentage error', function () {
    $sku = Sku::factory()->create();
    $ewmaVersion = MlModelVersion::factory()->create(['name' => 'baseline-moving-average', 'version' => 'v1']);
    $seasonalVersion = MlModelVersion::factory()->create(['name' => 'seasonal-naive', 'version' => 'v1']);

    $ewmaForecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $ewmaVersion->id]);
    ForecastAccuracy::factory()->create(['forecast_id' => $ewmaForecast->id, 'percentage_error' => 30.0]);

    $seasonalForecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $seasonalVersion->id]);
    ForecastAccuracy::factory()->create(['forecast_id' => $seasonalForecast->id, 'percentage_error' => 10.0]);

    expect(ModelSelectionFacade::chooseAlgorithm($sku->id))->toBe('seasonal_naive');
});

test('averages multiple scored forecasts per algorithm before comparing', function () {
    $sku = Sku::factory()->create();
    $ewmaVersion = MlModelVersion::factory()->create(['name' => 'baseline-moving-average', 'version' => 'v1']);
    $seasonalVersion = MlModelVersion::factory()->create(['name' => 'seasonal-naive', 'version' => 'v1']);

    foreach ([10.0, 10.0] as $error) {
        $forecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $ewmaVersion->id]);
        ForecastAccuracy::factory()->create(['forecast_id' => $forecast->id, 'percentage_error' => $error]);
    }

    // Seasonal-naive averages worse (20.0) despite one individually-good
    // score, because the average across both its scored forecasts matters,
    // not a single best result.
    foreach ([5.0, 35.0] as $error) {
        $forecast = Forecast::factory()->create(['sku_id' => $sku->id, 'model_version_id' => $seasonalVersion->id]);
        ForecastAccuracy::factory()->create(['forecast_id' => $forecast->id, 'percentage_error' => $error]);
    }

    expect(ModelSelectionFacade::chooseAlgorithm($sku->id))->toBe('ewma');
});

test('modelVersionFor registers one fixed row per algorithm, idempotently', function () {
    $ewma = ModelSelectionFacade::modelVersionFor('ewma');
    $seasonal = ModelSelectionFacade::modelVersionFor('seasonal_naive');

    expect($ewma->name)->toBe('baseline-moving-average');
    expect($seasonal->name)->toBe('seasonal-naive');
    expect($ewma->id)->not->toBe($seasonal->id);

    $ewmaAgain = ModelSelectionFacade::modelVersionFor('ewma');
    expect($ewmaAgain->id)->toBe($ewma->id);
});
