<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\InventoryDailySnapshot\InventoryDailySnapshotResource;
use Domain\Facades\InventoryDailySnapshotFacade\InventoryDailySnapshotFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only history — index only, plus a manual capture trigger for the day
 * that would otherwise wait for the nightly schedule. No migration-backed
 * create/edit/update/delete: rows are only ever written by
 * InventoryDailySnapshotService::captureDay(). See that Service's docblock.
 */
final class InventoryDailySnapshotController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'stockout_only', 'date_from', 'date_to']);

        $snapshots = InventoryDailySnapshotFacade::all($filters)
            ->through(fn ($snapshot) => (new InventoryDailySnapshotResource($snapshot))->resolve());

        return Inertia::render('InventoryDailySnapshot/index', [
            'snapshots' => $snapshots,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function capture(Request $request): RedirectResponse
    {
        $request->validate([
            'date' => ['nullable', 'date', Rule::date()->beforeOrEqual(now()->toDateString())],
        ]);

        $date = $request->filled('date') ? Carbon::parse($request->string('date')->toString()) : now()->subDay();

        $result = InventoryDailySnapshotFacade::captureDay($date);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
