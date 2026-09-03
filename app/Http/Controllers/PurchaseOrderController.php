<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\PurchaseOrder\CreatePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrder\PurchaseOrderResource;
use Domain\Facades\PurchaseOrderFacade\PurchaseOrderFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\SupplierFacade\SupplierFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Draft → Approved → Ordered → PartiallyReceived/Received, or Cancelled.
 * Editing and deleting are only allowed while Draft — see
 * PurchaseOrderService's docblock. approve()/markOrdered()/cancel() are the
 * status-transition actions; receiving is driven by the GoodsReceipt module.
 */
final class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'supplier_id', 'sort', 'direction']);

        $purchaseOrders = PurchaseOrderFacade::all($filters)
            ->through(fn ($purchaseOrder) => (new PurchaseOrderResource($purchaseOrder))->resolve());

        return Inertia::render('PurchaseOrder/index', [
            'purchaseOrders' => $purchaseOrders,
            'filters' => (object) $filters,
            'supplierOptions' => SupplierFacade::options(),
            'statuses' => collect(PurchaseOrderStatus::cases())->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PurchaseOrder/create', [
            'supplierOptions' => SupplierFacade::options(),
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::optionsWithPricing(),
        ]);
    }

    public function edit(int $id): Response
    {
        $purchaseOrder = PurchaseOrderFacade::get($id);

        abort_if($purchaseOrder === null, 404);

        return Inertia::render('PurchaseOrder/edit', [
            'id' => $id,
            'purchaseOrder' => (new PurchaseOrderResource($purchaseOrder))->resolve(),
            'supplierOptions' => SupplierFacade::options(),
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::optionsWithPricing(),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $purchaseOrders = PurchaseOrderFacade::all($request->only(['search', 'status', 'supplier_id', 'sort', 'direction', 'per_page']))
            ->through(fn ($purchaseOrder) => (new PurchaseOrderResource($purchaseOrder))->resolve());

        return response()->json($purchaseOrders);
    }

    public function get(int $id): JsonResponse
    {
        $purchaseOrder = PurchaseOrderFacade::get($id);

        return $purchaseOrder
            ? response()->json(['success' => true, 'data' => (new PurchaseOrderResource($purchaseOrder))->resolve()])
            : response()->json(['success' => false, 'message' => 'Purchase order not found'], 404);
    }

    public function store(CreatePurchaseOrderRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'created_by' => $request->user()?->id];

        $result = PurchaseOrderFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('purchase-order.edit', $result['data']->id) : back()->withInput();
    }

    public function update(UpdatePurchaseOrderRequest $request, int $id): RedirectResponse
    {
        $result = PurchaseOrderFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('purchase-order.edit', $id) : back()->withInput();
    }

    public function approve(int $id): RedirectResponse
    {
        $result = PurchaseOrderFacade::approve($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function markOrdered(int $id): RedirectResponse
    {
        $result = PurchaseOrderFacade::markOrdered($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function cancel(int $id): RedirectResponse
    {
        $result = PurchaseOrderFacade::cancel($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = PurchaseOrderFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
