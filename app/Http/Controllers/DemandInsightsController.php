<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Domain\Facades\DemandInsightsFacade\DemandInsightsFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two read-only computed reports over the same `inventory_daily_snapshots`
 * history — see DemandInsightsService's docblock. No migration, no model,
 * no create/edit/update/delete.
 */
final class DemandInsightsController extends Controller
{
    public function lostSales(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'lookback_days']);

        return Inertia::render('DemandInsights/lost-sales', [
            'rows' => DemandInsightsFacade::lostSales($filters),
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function anomalies(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'lookback_days']);

        return Inertia::render('DemandInsights/anomalies', [
            'rows' => DemandInsightsFacade::anomalies($filters),
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }
}
