<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\SalesReturn\SalesReturnResource;
use Domain\Facades\SalesReturnFacade\SalesReturnFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Immutable, posted-on-create — index (the return log) and create/store
 * only. See SalesReturnService's docblock.
 */
final class SalesReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'sales_order_id', 'sort', 'direction']);

        $salesReturns = SalesReturnFacade::all($filters)
            ->through(fn ($salesReturn) => (new SalesReturnResource($salesReturn))->resolve());

        return Inertia::render('SalesReturn/index', [
            'salesReturns' => $salesReturns,
            'filters' => (object) $filters,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $salesReturns = SalesReturnFacade::all($request->only(['search', 'sales_order_id', 'sort', 'direction', 'per_page']))
            ->through(fn ($salesReturn) => (new SalesReturnResource($salesReturn))->resolve());

        return response()->json($salesReturns);
    }
}
