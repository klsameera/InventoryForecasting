<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Warehouse\WarehouseResource;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
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
}
