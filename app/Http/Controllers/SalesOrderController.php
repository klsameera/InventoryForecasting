<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Http\Requests\SalesOrder\CreateSalesOrderRequest;
use App\Http\Requests\SalesOrder\UpdateSalesOrderRequest;
use App\Http\Resources\SalesOrder\SalesOrderResource;
use Domain\Facades\SalesOrderFacade\SalesOrderFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function create(): Response
    {
        return Inertia::render('SalesOrder/create', [
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::optionsWithPricing(),
        ]);
    }

    public function edit(int $id): Response
    {
        $salesOrder = SalesOrderFacade::get($id);

        abort_if($salesOrder === null, 404);

        return Inertia::render('SalesOrder/edit', [
            'id' => $id,
            'salesOrder' => (new SalesOrderResource($salesOrder))->resolve(),
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::optionsWithPricing(),
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

    public function store(CreateSalesOrderRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'created_by' => $request->user()?->id];

        $result = SalesOrderFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('sales-order.edit', $result['data']->id) : back()->withInput();
    }

    public function update(UpdateSalesOrderRequest $request, int $id): RedirectResponse
    {
        $result = SalesOrderFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('sales-order.edit', $id) : back()->withInput();
    }

    public function confirm(int $id): RedirectResponse
    {
        $result = SalesOrderFacade::confirm($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function cancel(int $id): RedirectResponse
    {
        $result = SalesOrderFacade::cancel($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = SalesOrderFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
