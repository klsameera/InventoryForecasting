<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\SupplierPerformanceMetric\SupplierPerformanceMetricResource;
use Domain\Facades\SupplierFacade\SupplierFacade;
use Domain\Facades\SupplierPerformanceFacade\SupplierPerformanceFacade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only history — index only, plus a manual capture trigger for a
 * calendar month, matching InventoryDailySnapshotController's pattern. No
 * migration-backed create/edit/update/delete: rows are only ever written by
 * SupplierPerformanceService::capturePeriod(). See that Service's docblock.
 */
final class SupplierPerformanceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'supplier_id']);

        $metrics = SupplierPerformanceFacade::all($filters)
            ->through(fn ($metric) => (new SupplierPerformanceMetricResource($metric))->resolve());

        return Inertia::render('SupplierPerformance/index', [
            'metrics' => $metrics,
            'filters' => (object) $filters,
            'supplierOptions' => SupplierFacade::options(),
        ]);
    }

    public function capture(Request $request): RedirectResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $periodStart = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->string('month')->toString())->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        if ($periodStart->greaterThan(now())) {
            $periodStart = now()->startOfMonth();
        }

        $periodEnd = $periodStart->copy()->endOfMonth();

        $result = SupplierPerformanceFacade::capturePeriod($periodStart, $periodEnd);

        Inertia::flash('toast', ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
