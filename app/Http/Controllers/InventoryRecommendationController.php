<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\InventoryRecommendation\InventoryRecommendationResource;
use Domain\Facades\InventoryRecommendationFacade\InventoryRecommendationFacade;
use Domain\Facades\SkuFacade\SkuFacade;
use Domain\Facades\WarehouseFacade\WarehouseFacade;
use Domain\Services\InventoryRecommendationService\InventoryRecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only listing plus the engine trigger and the four human-decision
 * actions (app_plan.md §53). No create/edit/update/delete: a recommendation
 * is only ever written by
 * {@see InventoryRecommendationService::generate()}. {@see centralAllocation()}
 * is a second, separate read-only view — app_plan.md §85's cross-warehouse
 * rollup, see app_architecture.md §1m.
 */
final class InventoryRecommendationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'warehouse_id', 'sku_id', 'status', 'recommendation_type', 'stockout_risk', 'overstock_risk']);

        $recommendations = InventoryRecommendationFacade::all($filters)
            ->through(fn ($recommendation) => (new InventoryRecommendationResource($recommendation))->resolve());

        return Inertia::render('InventoryRecommendation/index', [
            'recommendations' => $recommendations,
            'filters' => (object) $filters,
            'warehouseOptions' => WarehouseFacade::options(),
            'skuOptions' => SkuFacade::options(),
        ]);
    }

    public function centralAllocation(Request $request): Response
    {
        $filters = $request->only(['search', 'page', 'per_page']);

        $rows = InventoryRecommendationFacade::centralAllocation($filters);

        return Inertia::render('InventoryRecommendation/central-allocation', [
            'rows' => $rows,
            'filters' => (object) $filters,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $warehouseIds = $request->input('warehouse_ids');

        $result = InventoryRecommendationFacade::generate($warehouseIds ?: null);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function review(int $id): RedirectResponse
    {
        $result = InventoryRecommendationFacade::review($id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function accept(Request $request, int $id): RedirectResponse
    {
        $result = InventoryRecommendationFacade::accept($id, $request->user()?->id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function modify(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decided_qty' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = InventoryRecommendationFacade::modify($id, $validated['decided_qty'], $validated['reason'] ?? null, $request->user()?->id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = InventoryRecommendationFacade::reject($id, $validated['reason'] ?? null, $request->user()?->id);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
