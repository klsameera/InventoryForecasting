<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Http\Resources\PurchaseOrder\PurchaseOrderResource;
use Domain\Facades\PurchaseOrderFacade\PurchaseOrderFacade;
use Domain\Facades\SupplierFacade\SupplierFacade;
use Illuminate\Http\JsonResponse;
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
}
