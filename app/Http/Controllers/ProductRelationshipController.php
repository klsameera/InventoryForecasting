<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Domain\Facades\CategoryFacade\CategoryFacade;
use Domain\Facades\ProductRelationshipFacade\ProductRelationshipFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Three read-only computed reports — see ProductRelationshipService's
 * docblock. No migration, no model, no create/edit/update/delete.
 */
final class ProductRelationshipController extends Controller
{
    public function similarity(Request $request): Response
    {
        $skuId = $request->integer('sku_id') ?: null;

        return Inertia::render('ProductRelationship/similarity', [
            'result' => $skuId !== null ? ProductRelationshipFacade::similarSkus($skuId) : null,
            'skuId' => $skuId,
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function cannibalization(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id', 'lookback_days']);

        return Inertia::render('ProductRelationship/cannibalization', [
            'rows' => ProductRelationshipFacade::cannibalizationCandidates($filters),
            'filters' => (object) $filters,
            'categoryOptions' => CategoryFacade::options(),
        ]);
    }

    public function successors(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id']);

        return Inertia::render('ProductRelationship/successors', [
            'rows' => ProductRelationshipFacade::successorCandidates($filters),
            'filters' => (object) $filters,
            'categoryOptions' => CategoryFacade::options(),
        ]);
    }
}
