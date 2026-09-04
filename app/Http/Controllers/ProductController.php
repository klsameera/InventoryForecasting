<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Product\ProductResource;
use Domain\Facades\BrandFacade\BrandFacade;
use Domain\Facades\CategoryFacade\CategoryFacade;
use Domain\Facades\ProductFacade\ProductFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id', 'brand_id', 'product_type', 'status', 'sort', 'direction']);

        $products = ProductFacade::all($filters)
            ->through(fn ($product) => (new ProductResource($product))->resolve());

        return Inertia::render('Product/index', [
            'products' => $products,
            'filters' => (object) $filters,
            'categoryOptions' => CategoryFacade::options(),
            'brandOptions' => BrandFacade::options(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $products = ProductFacade::all($request->only(['search', 'category_id', 'brand_id', 'product_type', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($product) => (new ProductResource($product))->resolve());

        return response()->json($products);
    }

    public function get(int $id): JsonResponse
    {
        $product = ProductFacade::get($id);

        return $product
            ? response()->json(['success' => true, 'data' => (new ProductResource($product))->resolve()])
            : response()->json(['success' => false, 'message' => 'Product not found'], 404);
    }
}
