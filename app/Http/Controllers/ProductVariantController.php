<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProductVariant\CreateProductVariantRequest;
use App\Http\Requests\ProductVariant\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariant\ProductVariantResource;
use Domain\Facades\AttributeFacade\AttributeFacade;
use Domain\Facades\ProductFacade\ProductFacade;
use Domain\Facades\ProductVariantFacade\ProductVariantFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function create(Request $request): Response
    {
        return Inertia::render('ProductVariant/create', [
            'productId' => $request->integer('product_id') ?: null,
            'productOptions' => ProductFacade::options(),
            'attributeOptions' => AttributeFacade::optionsWithValues(),
        ]);
    }

    public function edit(int $id): Response
    {
        $variant = ProductVariantFacade::get($id);

        abort_if($variant === null, 404);

        return Inertia::render('ProductVariant/edit', [
            'id' => $id,
            'variant' => (new ProductVariantResource($variant))->resolve(),
            'productOptions' => ProductFacade::options(),
            'attributeOptions' => AttributeFacade::optionsWithValues(),
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

    public function store(CreateProductVariantRequest $request): RedirectResponse
    {
        $result = ProductVariantFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('product-variant.index') : back()->withInput();
    }

    public function update(UpdateProductVariantRequest $request, int $id): RedirectResponse
    {
        $result = ProductVariantFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('product-variant.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = ProductVariantFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
