<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\Inventory\InventoryResource;
use Domain\Facades\InventoryFacade\InventoryFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only. Inventory balances are derived from the stock movement ledger —
 * see {@see StockMovementController} for the write path. No create, store,
 * edit, update or delete routes exist for this module. See app_plan.md §88.
 */
final class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'sort', 'direction']);

        $inventories = InventoryFacade::all($filters)
            ->through(fn ($inventory) => (new InventoryResource($inventory))->resolve());

        return Inertia::render('Inventory/index', [
            'inventories' => $inventories,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $inventories = InventoryFacade::all($request->only(['search', 'warehouse_id', 'sku_id', 'sort', 'direction', 'per_page']))
            ->through(fn ($inventory) => (new InventoryResource($inventory))->resolve());

        return response()->json($inventories);
    }
}
