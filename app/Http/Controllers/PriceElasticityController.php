<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Domain\Facades\PriceElasticityFacade\PriceElasticityFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only computed report — see PriceElasticityService's docblock. No
 * migration, no model, no create/edit/update/delete.
 */
final class PriceElasticityController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'sku_id', 'lookback_days']);

        return Inertia::render('PriceElasticity/index', [
            'rows' => PriceElasticityFacade::report($filters),
            'filters' => (object) $filters,
            'skuOptions' => SkuFacade::options(),
        ]);
    }
}
