<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Supplier\CreateSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Supplier\SupplierResource;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\SupplierFacade\SupplierFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $suppliers = SupplierFacade::all($filters)
            ->through(fn ($supplier) => (new SupplierResource($supplier))->resolve());

        return Inertia::render('Supplier/index', [
            'suppliers' => $suppliers,
            'filters' => (object) $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Supplier/create', [
            'skuOptions' => SkuFacade::optionsWithPricing(),
        ]);
    }

    public function edit(int $id): Response
    {
        $supplier = SupplierFacade::get($id);

        abort_if($supplier === null, 404);

        return Inertia::render('Supplier/edit', [
            'id' => $id,
            'supplier' => (new SupplierResource($supplier))->resolve(),
            'skuOptions' => SkuFacade::optionsWithPricing(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $suppliers = SupplierFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($supplier) => (new SupplierResource($supplier))->resolve());

        return response()->json($suppliers);
    }

    public function get(int $id): JsonResponse
    {
        $supplier = SupplierFacade::get($id);

        return $supplier
            ? response()->json(['success' => true, 'data' => (new SupplierResource($supplier))->resolve()])
            : response()->json(['success' => false, 'message' => 'Supplier not found'], 404);
    }

    public function store(CreateSupplierRequest $request): RedirectResponse
    {
        $result = SupplierFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('supplier.index') : back()->withInput();
    }

    public function update(UpdateSupplierRequest $request, int $id): RedirectResponse
    {
        $result = SupplierFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('supplier.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = SupplierFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
