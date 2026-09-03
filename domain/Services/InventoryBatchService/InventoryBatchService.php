<?php

declare(strict_types=1);

namespace Domain\Services\InventoryBatchService;

use App\Models\InventoryBatch;
use Carbon\CarbonInterface;
use Domain\Services\StockMovementService\StockMovementService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FIFO lot ledger. Never written to directly from a controller — {@see receive()}
 * and {@see consumeFifo()} are called by
 * {@see StockMovementService::post()}
 * alongside every inventory-affecting movement, inside that caller's
 * transaction (neither method opens its own). See app_plan.md §23.
 */
final class InventoryBatchService
{
    public function __construct(private InventoryBatch $model) {}

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
            ->when($filters['open_only'] ?? null, fn ($query) => $query
                ->where('remaining_qty', '>', 0))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($q) => $q->where('sku', 'like', "%{$search}%")))
            ->orderBy('received_date')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Open a new batch on an inbound movement.
     */
    public function receive(
        int $warehouseId,
        int $skuId,
        string $sourceType,
        int $sourceId,
        int $quantity,
        float $unitCost,
        CarbonInterface $receivedDate,
    ): void {
        try {
            $this->model->create([
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'received_date' => $receivedDate,
                'received_qty' => $quantity,
                'remaining_qty' => $quantity,
                'unit_cost' => $unitCost,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed opening inventory batch', [
                'exception' => $exception->getMessage(),
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
            ]);
        }
    }

    /**
     * Consume the oldest open batches first. Best-effort: stock predating
     * batch tracking (Phase 1 movements, or any gap) has no batch to draw
     * from, so this never blocks the movement it's attached to — the
     * on-hand-quantity guard in InventoryService::applyMovement() is the
     * authoritative stock check, not this ledger. Returns the weighted
     * average cost of whatever it did manage to consume, or null if no open
     * batches were found at all.
     *
     * @return array{weighted_cost: float|null, consumed_qty: int}
     */
    public function consumeFifo(int $warehouseId, int $skuId, int $quantity): array
    {
        try {
            $batches = $this->model->newQuery()
                ->where('warehouse_id', $warehouseId)
                ->where('sku_id', $skuId)
                ->where('remaining_qty', '>', 0)
                ->orderBy('received_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remainingToConsume = $quantity;
            $consumedValue = 0.0;
            $consumedQty = 0;

            foreach ($batches as $batch) {
                if ($remainingToConsume <= 0) {
                    break;
                }

                $take = min($batch->remaining_qty, $remainingToConsume);

                $batch->remaining_qty -= $take;
                $batch->save();

                $consumedValue += $take * (float) $batch->unit_cost;
                $consumedQty += $take;
                $remainingToConsume -= $take;
            }

            return [
                'weighted_cost' => $consumedQty > 0 ? round($consumedValue / $consumedQty, 2) : null,
                'consumed_qty' => $consumedQty,
            ];
        } catch (Throwable $exception) {
            Log::error('Failed consuming inventory batches', [
                'exception' => $exception->getMessage(),
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
            ]);

            return ['weighted_cost' => null, 'consumed_qty' => 0];
        }
    }
}
