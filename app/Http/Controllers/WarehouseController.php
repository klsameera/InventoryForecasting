<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Warehouse\CreateWarehouseRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Http\Resources\Warehouse\WarehouseResource;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WarehouseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $warehouses = WarehouseFacade::all($filters)
            ->through(fn ($warehouse) => (new WarehouseResource($warehouse))->resolve());

        return Inertia::render('Warehouse/index', [
            'warehouses' => $warehouses,
            'filters' => (object) $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouse/create');
    }

    public function edit(int $id): Response
    {
        $warehouse = WarehouseFacade::get($id);

        abort_if($warehouse === null, 404);

        return Inertia::render('Warehouse/edit', [
            'id' => $id,
            'warehouse' => (new WarehouseResource($warehouse))->resolve(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $warehouses = WarehouseFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($warehouse) => (new WarehouseResource($warehouse))->resolve());

        return response()->json($warehouses);
    }

    public function get(int $id): JsonResponse
    {
        $warehouse = WarehouseFacade::get($id);

        return $warehouse
            ? response()->json(['success' => true, 'data' => (new WarehouseResource($warehouse))->resolve()])
            : response()->json(['success' => false, 'message' => 'Warehouse not found'], 404);
    }

    public function store(CreateWarehouseRequest $request): RedirectResponse
    {
        $result = WarehouseFacade::store($request->validated());

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('warehouse.index') : back()->withInput();
    }

    public function update(UpdateWarehouseRequest $request, int $id): RedirectResponse
    {
        $result = WarehouseFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('warehouse.index') : back()->withInput();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = WarehouseFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
