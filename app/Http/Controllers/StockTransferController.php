<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StockTransferStatus;
use App\Http\Requests\StockTransfer\CreateStockTransferRequest;
use App\Http\Requests\StockTransfer\UpdateStockTransferRequest;
use App\Http\Resources\StockTransfer\StockTransferResource;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\StockTransferFacade\StockTransferFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function create(): Response
    {
        return Inertia::render('StockTransfer/create', [
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function edit(int $id): Response
    {
        $stockTransfer = StockTransferFacade::get($id);

        abort_if($stockTransfer === null, 404);

        return Inertia::render('StockTransfer/edit', [
            'id' => $id,
            'stockTransfer' => (new StockTransferResource($stockTransfer))->resolve(),
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
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

    public function store(CreateStockTransferRequest $request): RedirectResponse
    {
        $data = [...$request->validated(), 'created_by' => $request->user()?->id];

        $result = StockTransferFacade::store($data);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('stock-transfer.edit', $result['data']->id) : back()->withInput();
    }

    public function update(UpdateStockTransferRequest $request, int $id): RedirectResponse
    {
        $result = StockTransferFacade::update($request->validated(), $id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return $result['success'] ? to_route('stock-transfer.edit', $id) : back()->withInput();
    }

    public function approve(int $id): RedirectResponse
    {
        $result = StockTransferFacade::approve($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function dispatch(int $id): RedirectResponse
    {
        $result = StockTransferFacade::dispatch($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function receive(int $id): RedirectResponse
    {
        $result = StockTransferFacade::receive($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function cancel(int $id): RedirectResponse
    {
        $result = StockTransferFacade::cancel($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function delete(int $id): RedirectResponse
    {
        $result = StockTransferFacade::delete($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
