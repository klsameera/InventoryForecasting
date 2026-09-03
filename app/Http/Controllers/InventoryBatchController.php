<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\InventoryBatch\InventoryBatchResource;
use Domain\Facades\InventoryBatchFacade\InventoryBatchFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only FIFO lot ledger — index/all only, no create/edit/delete. Batches
 * are written only by InventoryBatchService, called from
 * StockMovementService::post(). See app_plan.md §23, §88.
 */
final class InventoryBatchController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'open_only', 'sort', 'direction']);

        $batches = InventoryBatchFacade::all($filters)
            ->through(fn ($batch) => (new InventoryBatchResource($batch))->resolve());

        return Inertia::render('InventoryBatch/index', [
            'batches' => $batches,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $batches = InventoryBatchFacade::all($request->only(['search', 'warehouse_id', 'sku_id', 'open_only', 'sort', 'direction', 'per_page']))
            ->through(fn ($batch) => (new InventoryBatchResource($batch))->resolve());

        return response()->json($batches);
    }
}
