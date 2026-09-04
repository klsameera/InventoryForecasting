<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProductVariant\ProductVariantResource;
use Domain\Facades\ProductFacade\ProductFacade;
use Domain\Facades\ProductVariantFacade\ProductVariantFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductVariantController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'product_id', 'status', 'sort', 'direction']);

        $variants = ProductVariantFacade::all($filters)
            ->through(fn ($variant) => (new ProductVariantResource($variant))->resolve());

        return Inertia::render('ProductVariant/index', [
            'variants' => $variants,
            'filters' => (object) $filters,
            'productOptions' => ProductFacade::options(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $variants = ProductVariantFacade::all($request->only(['search', 'product_id', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($variant) => (new ProductVariantResource($variant))->resolve());

        return response()->json($variants);
    }

    public function get(int $id): JsonResponse
    {
        $variant = ProductVariantFacade::get($id);

        return $variant
            ? response()->json(['success' => true, 'data' => (new ProductVariantResource($variant))->resolve()])
            : response()->json(['success' => false, 'message' => 'Variant not found'], 404);
    }
}
