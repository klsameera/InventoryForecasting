<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Sku\CreateSkuRequest;
use App\Http\Requests\Sku\UpdateSkuRequest;
use App\Http\Resources\Sku\SkuResource;
use Domain\Facades\ProductFacade\ProductFacade;
use Domain\Facades\ProductVariantFacade\ProductVariantFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function create(Request $request): Response
    {
        return Inertia::render('Sku/create', [
            'productId' => $request->integer('product_id') ?: null,
            'productOptions' => ProductFacade::options(),
            'variantOptions' => ProductVariantFacade::allOptions(),
        ]);
    }

    public function edit(int $id): Response
    {
        $sku = SkuFacade::get($id);

        abort_if($sku === null, 404);

        return Inertia::render('Sku/edit', [
            'id' => $id,
            'sku' => (new SkuResource($sku))->resolve(),
            'productOptions' => ProductFacade::options(),
            'variantOptions' => ProductVariantFacade::allOptions(),
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

    public function store(CreateSkuRequest $request): RedirectResponse
    {
        $result = SkuFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('sku.index') : back()->withInput();
    }

    public function update(UpdateSkuRequest $request, int $id): RedirectResponse
    {
        $result = SkuFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('sku.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = SkuFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
