<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Sku\SkuResource;
use Domain\Facades\ProductFacade\ProductFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SkuController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'product_id', 'status', 'sort', 'direction']);

        $skus = SkuFacade::all($filters)
            ->through(fn ($sku) => (new SkuResource($sku))->resolve());

        return Inertia::render('Sku/index', [
            'skus' => $skus,
            'filters' => (object) $filters,
            'productOptions' => ProductFacade::options(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $skus = SkuFacade::all($request->only(['search', 'product_id', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($sku) => (new SkuResource($sku))->resolve());

        return response()->json($skus);
    }

    public function get(int $id): JsonResponse
    {
        $sku = SkuFacade::get($id);

        return $sku
            ? response()->json(['success' => true, 'data' => (new SkuResource($sku))->resolve()])
            : response()->json(['success' => false, 'message' => 'Sku not found'], 404);
    }
}
