<?php

declare(strict_types=1);

namespace Domain\Services\SupplierPerformanceService;

use App\Models\SupplierPerformanceMetric;
use Carbon\CarbonInterface;
use Domain\Services\InventoryDailySnapshotService\InventoryDailySnapshotService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10 (app_plan.md §86, "supplier lead-time prediction") — the
 * `supplier_performance_metrics` schema app_plan.md documents (line ~750)
 * but never got a migration until now. The plan's own worked example is
 * exactly this feature: "Supplier says 15 days... when historically they
 * actually take 20–25 days" — {@see capturePeriod()} computes that real
 * gap from actual `purchase_orders`/`goods_receipts` timestamps, never a
 * fabricated estimate.
 *
 * Six of the plan's seven metrics are computed from real data:
 * `ordered_qty`/`received_qty` (from `purchase_order_items`),
 * `average_lead_time_days`/`lead_time_std_dev` (order_date to first
 * `goods_receipts.received_date`), `on_time_percentage` (against
 * `purchase_orders.expected_date`, only counting POs that set one), and
 * `fill_rate`. **`quality_issue_rate` is left `null` permanently** — no
 * goods receipt in this schema records a quality/defect/rejection signal
 * to compute it from, and inventing one would be exactly the kind of
 * fabricated number this codebase has avoided in every prior phase (same
 * category as Phase 8's ageing risk using 2 of 8 listed inputs).
 */
final class SupplierPerformanceService
{
    public function __construct(private SupplierPerformanceMetric $model) {}

    public function count(): int
    {
        return $this->model->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): LengthAwarePaginator
    {
        return $this->model
            ->newQuery()
            ->with('supplier:id,name,default_lead_time_days')
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query
                ->where('supplier_id', $supplierId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', "%{$search}%")))
            ->orderByDesc('period_start')
            ->orderBy('supplier_id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Captures one calendar-month period for every supplier that placed at
     * least one purchase order within it. Safe to re-run for a past period
     * (recomputes and overwrites, the same "capture is always correct to
     * re-run" property {@see InventoryDailySnapshotService}
     * already has).
     *
     * @return array<string, mixed>
     */
    public function capturePeriod(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        DB::beginTransaction();

        try {
            $supplierIds = DB::table('purchase_orders')
                ->whereDate('order_date', '>=', $periodStart->toDateString())
                ->whereDate('order_date', '<=', $periodEnd->toDateString())
                ->distinct()
                ->pluck('supplier_id');

            foreach ($supplierIds as $supplierId) {
                $this->captureForSupplier((int) $supplierId, $periodStart, $periodEnd);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => "Captured supplier performance for {$periodStart->toDateString()} to {$periodEnd->toDateString()} across {$supplierIds->count()} supplier(s)",
                'data' => ['period_start' => $periodStart->toDateString(), 'suppliers' => $supplierIds->count()],
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed capturing supplier performance metrics', [
                'exception' => $exception->getMessage(),
                'period_start' => $periodStart->toDateString(),
            ]);

            return ['success' => false, 'message' => 'Error capturing supplier performance metrics'];
        }
    }

    private function captureForSupplier(int $supplierId, CarbonInterface $periodStart, CarbonInterface $periodEnd): void
    {
        $purchaseOrders = DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->whereDate('order_date', '>=', $periodStart->toDateString())
            ->whereDate('order_date', '<=', $periodEnd->toDateString())
            ->get(['id', 'order_date', 'expected_date']);

        $poIds = $purchaseOrders->pluck('id');

        $itemTotals = DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', $poIds)
            ->selectRaw('COALESCE(SUM(quantity), 0) as ordered, COALESCE(SUM(received_qty), 0) as received')
            ->first();

        $orderedQty = (int) $itemTotals->ordered;
        $receivedQty = (int) $itemTotals->received;
        $fillRate = $orderedQty > 0 ? round(($receivedQty / $orderedQty) * 100, 2) : null;

        $firstReceiptByPo = DB::table('goods_receipts')
            ->whereIn('purchase_order_id', $poIds)
            ->select('purchase_order_id', DB::raw('MIN(received_date) as first_received_date'))
            ->groupBy('purchase_order_id')
            ->get()
            ->keyBy('purchase_order_id');

        $leadTimes = [];
        $onTimeCount = 0;
        $withExpectedDateCount = 0;

        foreach ($purchaseOrders as $purchaseOrder) {
            $receipt = $firstReceiptByPo->get($purchaseOrder->id);

            if ($receipt === null) {
                continue;
            }

            $orderDate = Carbon::parse($purchaseOrder->order_date);
            $receivedDate = Carbon::parse($receipt->first_received_date);
            $leadTimes[] = abs($orderDate->diffInDays($receivedDate));

            if ($purchaseOrder->expected_date !== null) {
                $withExpectedDateCount++;

                if ($receivedDate->lessThanOrEqualTo(Carbon::parse($purchaseOrder->expected_date))) {
                    $onTimeCount++;
                }
            }
        }

        $averageLeadTimeDays = $leadTimes === [] ? null : round(array_sum($leadTimes) / count($leadTimes), 2);
        $leadTimeStdDev = count($leadTimes) >= 2 ? round($this->stdDev($leadTimes), 2) : null;
        $onTimePercentage = $withExpectedDateCount > 0 ? round(($onTimeCount / $withExpectedDateCount) * 100, 2) : null;

        $attributes = [
            'period_end' => $periodEnd->toDateString(),
            'ordered_qty' => $orderedQty,
            'received_qty' => $receivedQty,
            'average_lead_time_days' => $averageLeadTimeDays,
            'lead_time_std_dev' => $leadTimeStdDev,
            'on_time_percentage' => $onTimePercentage,
            'fill_rate' => $fillRate,
            'quality_issue_rate' => null,
        ];

        // Not updateOrCreate(): the same 'date'-cast-stores-with-time trap
        // app_architecture.md §1h documents for inventory_daily_snapshots —
        // updateOrCreate()'s search array would build a plain
        // where('period_start', '2026-08-01') that never matches the stored
        // '2026-08-01 00:00:00' value, so re-capturing a period would insert
        // a duplicate row and trip the unique constraint instead of updating.
        $existing = $this->model->newQuery()
            ->where('supplier_id', $supplierId)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        $this->model->create([
            'supplier_id' => $supplierId,
            'period_start' => $periodStart->toDateString(),
            ...$attributes,
        ]);
    }

    /**
     * @param  list<int>  $values
     */
    private function stdDev(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $values)) / (count($values) - 1);

        return sqrt($variance);
    }
}
