<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Http\Requests\StockMovement\CreateStockMovementRequest;
use App\Http\Resources\StockMovement\StockMovementResource;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\StockMovementFacade\StockMovementFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Append-only ledger. Only index (the ledger view) and create/store (manual
 * adjustment entry) exist — no edit, update or delete routes. See
 * app_plan.md §14 and StockMovementService's docblock.
 */
final class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'movement_type', 'sort', 'direction']);

        $movements = StockMovementFacade::all($filters)
            ->through(fn ($movement) => (new StockMovementResource($movement))->resolve());

        return Inertia::render('StockMovement/index', [
            'movements' => $movements,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
            'movementTypes' => collect(MovementType::cases())->map(fn (MovementType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('StockMovement/create', [
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
            'movementTypes' => collect(MovementType::manualEntryCases())->map(fn (MovementType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $movements = StockMovementFacade::all($request->only(['search', 'warehouse_id', 'sku_id', 'movement_type', 'per_page']))
            ->through(fn ($movement) => (new StockMovementResource($movement))->resolve());

        return response()->json($movements);
    }

    public function store(CreateStockMovementRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'user_id' => $request->user()?->id];

        $result = StockMovementFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('stock-movement.index') : back()->withInput();
    }
}
