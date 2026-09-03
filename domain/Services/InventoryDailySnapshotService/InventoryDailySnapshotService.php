<?php

declare(strict_types=1);

namespace Domain\Services\InventoryDailySnapshotService;

use App\Enums\MovementType;
use App\Models\InventoryDailySnapshot;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 4 — the data pipeline (app_plan.md §80). One table,
 * `inventory_daily_snapshots` (§15), covers all three of that phase's
 * bullets at once: it *is* the daily snapshot, its `sold_qty` column *is*
 * the demand aggregation, and its `stockout_flag`/`stockout_minutes` columns
 * *are* the stockout history — there was no reason to split these into
 * separate tables when the plan's own schema for §15 already carries all
 * three.
 *
 * Every figure is reconstructed from the {@see StockMovement}
 * ledger, never from a running total, so capturing (or re-capturing) any
 * date — including one far in the past — is always correct: opening/closing
 * quantities are the net signed sum of every movement up to that day's
 * boundary (with the previous day's snapshot used as a shortcut when it
 * exists, falling back to a full replay when it doesn't).
 */
final class InventoryDailySnapshotService
{
    public function __construct(private InventoryDailySnapshot $model) {}

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
            ->with(['warehouse:id,name', 'sku:id,sku,product_id', 'sku.product:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sku_id', $skuId))
            ->when($filters['stockout_only'] ?? null, fn ($query) => $query
                ->where('stockout_flag', true))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query
                ->whereDate('snapshot_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query
                ->whereDate('snapshot_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($q) => $q->where('sku', 'like', "%{$search}%")))
            ->orderByDesc('snapshot_date')
            ->orderBy('warehouse_id')
            ->orderBy('sku_id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Capture (or re-capture) one calendar date for every warehouse/SKU pair
     * that has ever had a stock movement or currently has a balance.
     *
     * @return array<string, mixed>
     */
    public function captureDay(CarbonInterface $date): array
    {
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = Carbon::parse($date)->endOfDay();
        $boundary = $dayEnd->greaterThan(now()) ? now() : $dayEnd;

        DB::beginTransaction();

        try {
            $pairs = $this->pairsToSnapshot();

            foreach ($pairs as $pair) {
                $this->captureForPair((int) $pair->warehouse_id, (int) $pair->sku_id, $dayStart, $dayEnd, $boundary);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => "Captured snapshot for {$dayStart->toDateString()} across {$pairs->count()} warehouse/SKU pairs",
                'data' => ['date' => $dayStart->toDateString(), 'pairs' => $pairs->count()],
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed capturing inventory daily snapshot', [
                'exception' => $exception->getMessage(),
                'date' => $dayStart->toDateString(),
            ]);

            return ['success' => false, 'message' => 'Error capturing snapshot'];
        }
    }

    /**
     * @return Collection<int, object{warehouse_id: int, sku_id: int}>
     */
    private function pairsToSnapshot(): Collection
    {
        $fromMovements = DB::table('stock_movements')->select(['warehouse_id', 'sku_id'])->distinct();

        return DB::table('inventories')
            ->select(['warehouse_id', 'sku_id'])
            ->distinct()
            ->union($fromMovements)
            ->get();
    }

    private function captureForPair(int $warehouseId, int $skuId, CarbonInterface $dayStart, CarbonInterface $dayEnd, CarbonInterface $boundary): void
    {
        $priorSnapshot = $this->model->newQuery()
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->whereDate('snapshot_date', $dayStart->copy()->subDay()->toDateString())
            ->first();

        $openingQty = $priorSnapshot?->closing_qty
            ?? $this->balanceAsOf($warehouseId, $skuId, $dayStart->copy()->subSecond());

        $dayMovements = DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('occurred_at', '>=', $dayStart)
            ->where('occurred_at', '<=', $boundary)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['movement_type', 'quantity', 'occurred_at']);

        $totals = [
            'received_qty' => 0, 'sold_qty' => 0, 'returned_qty' => 0,
            'transfer_in_qty' => 0, 'transfer_out_qty' => 0, 'adjustment_qty' => 0,
        ];

        $balance = $openingQty;
        $cursor = $dayStart;
        $stockoutSeconds = 0;

        foreach ($dayMovements as $movement) {
            $type = MovementType::from($movement->movement_type);
            $occurredAt = Carbon::parse($movement->occurred_at);
            $signedQty = $type->isInbound() ? $movement->quantity : -$movement->quantity;

            match ($type) {
                MovementType::PurchaseReceipt => $totals['received_qty'] += $movement->quantity,
                MovementType::Sale => $totals['sold_qty'] += $movement->quantity,
                MovementType::SaleReturn => $totals['returned_qty'] += $movement->quantity,
                MovementType::TransferIn => $totals['transfer_in_qty'] += $movement->quantity,
                MovementType::TransferOut => $totals['transfer_out_qty'] += $movement->quantity,
                default => $totals['adjustment_qty'] += $signedQty,
            };

            if ($balance <= 0 && $occurredAt->greaterThan($cursor)) {
                $stockoutSeconds += abs($occurredAt->diffInSeconds($cursor));
            }

            $balance += $signedQty;
            $cursor = $occurredAt;
        }

        if ($balance <= 0 && $boundary->greaterThan($cursor)) {
            $stockoutSeconds += abs($boundary->diffInSeconds($cursor));
        }

        $closingQty = $balance;
        $averageCost = (float) (DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->value('average_cost') ?? 0);
        $reservedQty = (int) (DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->value('reserved_qty') ?? 0);

        $stockoutMinutes = $stockoutSeconds > 0 ? (int) round($stockoutSeconds / 60) : null;

        $attributes = [
            'opening_qty' => $openingQty,
            ...$totals,
            'closing_qty' => $closingQty,
            'available_qty' => max(0, $closingQty - $reservedQty),
            'stockout_minutes' => $stockoutMinutes,
            'stockout_flag' => $stockoutMinutes !== null,
            'inventory_value' => round($closingQty * $averageCost, 2),
        ];

        // Deliberately not updateOrCreate(): its search criteria builds a
        // plain where('snapshot_date', 'Y-m-d') comparison, but this column
        // is stored as 'Y-m-d 00:00:00' (the 'date' cast doesn't strip the
        // time on write), so it never matches an existing row and always
        // tries to insert — tripping the table's unique constraint on every
        // re-capture. whereDate() correctly ignores the stored time part.
        $existing = $this->model->newQuery()
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->whereDate('snapshot_date', $dayStart->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        $this->model->create([
            'snapshot_date' => $dayStart->toDateString(),
            'warehouse_id' => $warehouseId,
            'sku_id' => $skuId,
            ...$attributes,
        ]);
    }

    /**
     * Net signed sum of every movement for a pair up to (and including) a
     * point in time — the fallback used when no prior-day snapshot exists
     * to shortcut from (first-ever capture for that pair, or a backfill
     * that predates any snapshot history).
     */
    private function balanceAsOf(int $warehouseId, int $skuId, CarbonInterface $asOf): int
    {
        $rows = DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('occurred_at', '<=', $asOf)
            ->select(['movement_type', DB::raw('SUM(quantity) as total')])
            ->groupBy('movement_type')
            ->get();

        return (int) $rows->sum(function ($row) {
            $type = MovementType::from($row->movement_type);

            return $type->isInbound() ? $row->total : -$row->total;
        });
    }
}
