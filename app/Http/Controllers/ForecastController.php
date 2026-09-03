<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Forecast\ForecastResource;
use Domain\Facades\ForecastFacade\ForecastFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only — forecasts are written only by
 * ForecastRunService::processRun(). The one action here, scoreAccuracy, is
 * a manual trigger for the nightly app:score-forecast-accuracy schedule.
 */
final class ForecastController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id']);

        $forecasts = ForecastFacade::all($filters)
            ->through(fn ($forecast) => (new ForecastResource($forecast))->resolve());

        return Inertia::render('Forecast/index', [
            'forecasts' => $forecasts,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function scoreAccuracy(): RedirectResponse
    {
        $result = ForecastFacade::scoreAccuracy();

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
