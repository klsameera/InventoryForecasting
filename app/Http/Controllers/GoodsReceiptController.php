<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\GoodsReceipt\GoodsReceiptResource;
use Domain\Facades\GoodsReceiptFacade\GoodsReceiptFacade;
use Illuminate\Http\JsonResponse;
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

    public function all(Request $request): JsonResponse
    {
        $goodsReceipts = GoodsReceiptFacade::all($request->only(['search', 'warehouse_id', 'purchase_order_id', 'sort', 'direction', 'per_page']))
            ->through(fn ($goodsReceipt) => (new GoodsReceiptResource($goodsReceipt))->resolve());

        return response()->json($goodsReceipts);
    }
}
