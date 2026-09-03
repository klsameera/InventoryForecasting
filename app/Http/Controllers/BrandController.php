<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Brand\CreateBrandRequest;
use App\Http\Requests\Brand\UpdateBrandRequest;
use App\Http\Resources\Brand\BrandResource;
use Domain\Facades\BrandFacade\BrandFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $brands = BrandFacade::all($filters)
            ->through(fn ($brand) => (new BrandResource($brand))->resolve());

        return Inertia::render('Brand/index', [
            'brands' => $brands,
            'filters' => (object) $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Brand/create');
    }

    public function edit(int $id): Response
    {
        $brand = BrandFacade::get($id);

        abort_if($brand === null, 404);

        return Inertia::render('Brand/edit', [
            'id' => $id,
            'brand' => (new BrandResource($brand))->resolve(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $brands = BrandFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($brand) => (new BrandResource($brand))->resolve());

        return response()->json($brands);
    }

    public function get(int $id): JsonResponse
    {
        $brand = BrandFacade::get($id);

        return $brand
            ? response()->json(['success' => true, 'data' => (new BrandResource($brand))->resolve()])
            : response()->json(['success' => false, 'message' => 'Brand not found'], 404);
    }

    public function store(CreateBrandRequest $request): RedirectResponse
    {
        $result = BrandFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('brand.index') : back()->withInput();
    }

    public function update(UpdateBrandRequest $request, int $id): RedirectResponse
    {
        $result = BrandFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('brand.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = BrandFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
