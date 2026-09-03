<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GoodsReceipt\CreateGoodsReceiptRequest;
use App\Http\Resources\GoodsReceipt\GoodsReceiptResource;
use App\Http\Resources\PurchaseOrder\PurchaseOrderResource;
use Domain\Facades\GoodsReceiptFacade\GoodsReceiptFacade;
use Domain\Facades\PurchaseOrderFacade\PurchaseOrderFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Immutable once posted — index (the receipt log) and create/store only. See
 * GoodsReceiptService's docblock.
 */
final class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'purchase_order_id', 'sort', 'direction']);

        $goodsReceipts = GoodsReceiptFacade::all($filters)
            ->through(fn ($goodsReceipt) => (new GoodsReceiptResource($goodsReceipt))->resolve());

        return Inertia::render('GoodsReceipt/index', [
            'goodsReceipts' => $goodsReceipts,
            'filters' => (object) $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $purchaseOrderId = $request->integer('purchase_order_id') ?: null;
        $purchaseOrder = $purchaseOrderId !== null ? PurchaseOrderFacade::get($purchaseOrderId) : null;

        return Inertia::render('GoodsReceipt/create', [
            'purchaseOrderOptions' => PurchaseOrderFacade::receivableOptions(),
            'purchaseOrder' => $purchaseOrder !== null ? (new PurchaseOrderResource($purchaseOrder))->resolve() : null,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $goodsReceipts = GoodsReceiptFacade::all($request->only(['search', 'warehouse_id', 'purchase_order_id', 'sort', 'direction', 'per_page']))
            ->through(fn ($goodsReceipt) => (new GoodsReceiptResource($goodsReceipt))->resolve());

        return response()->json($goodsReceipts);
    }

    public function store(CreateGoodsReceiptRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'received_by' => $request->user()?->id];

        $result = GoodsReceiptFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('goods-receipt.index') : back()->withInput();
    }
}
