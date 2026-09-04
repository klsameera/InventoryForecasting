<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Http\Resources\SalesOrder\SalesOrderResource;
use Domain\Facades\SalesOrderFacade\SalesOrderFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Draft → Confirmed, or Cancelled from Draft only. Editing and deleting are
 * only allowed while Draft — see SalesOrderService's docblock.
 */
final class SalesOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'warehouse_id', 'sort', 'direction']);

        $salesOrders = SalesOrderFacade::all($filters)
            ->through(fn ($salesOrder) => (new SalesOrderResource($salesOrder))->resolve());

        return Inertia::render('SalesOrder/index', [
            'salesOrders' => $salesOrders,
            'filters' => (object) $filters,
            'statuses' => collect(SalesOrderStatus::cases())->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()]),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $salesOrders = SalesOrderFacade::all($request->only(['search', 'status', 'warehouse_id', 'sort', 'direction', 'per_page']))
            ->through(fn ($salesOrder) => (new SalesOrderResource($salesOrder))->resolve());

        return response()->json($salesOrders);
    }

    public function get(int $id): JsonResponse
    {
        $salesOrder = SalesOrderFacade::get($id);

        return $salesOrder
            ? response()->json(['success' => true, 'data' => (new SalesOrderResource($salesOrder))->resolve()])
            : response()->json(['success' => false, 'message' => 'Sales order not found'], 404);
    }
}
