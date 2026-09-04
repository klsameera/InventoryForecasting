<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StockTransferStatus;
use App\Http\Resources\StockTransfer\StockTransferResource;
use Domain\Facades\StockTransferFacade\StockTransferFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Draft → Approved → Dispatched → Received, or Cancelled from Draft/Approved.
 * Editing and deleting are only allowed while Draft — see
 * StockTransferService's docblock.
 */
final class StockTransferController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $stockTransfers = StockTransferFacade::all($filters)
            ->through(fn ($stockTransfer) => (new StockTransferResource($stockTransfer))->resolve());

        return Inertia::render('StockTransfer/index', [
            'stockTransfers' => $stockTransfers,
            'filters' => (object) $filters,
            'statuses' => collect(StockTransferStatus::cases())->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()]),
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $stockTransfers = StockTransferFacade::all($request->only(['search', 'status', 'sort', 'direction', 'per_page']))
            ->through(fn ($stockTransfer) => (new StockTransferResource($stockTransfer))->resolve());

        return response()->json($stockTransfers);
    }

    public function get(int $id): JsonResponse
    {
        $stockTransfer = StockTransferFacade::get($id);

        return $stockTransfer
            ? response()->json(['success' => true, 'data' => (new StockTransferResource($stockTransfer))->resolve()])
            : response()->json(['success' => false, 'message' => 'Stock transfer not found'], 404);
    }
}
