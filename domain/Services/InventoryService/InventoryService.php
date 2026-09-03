<?php

declare(strict_types=1);

namespace Domain\Services\InventoryService;

use App\Models\Inventory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inventory balances are derived — never written to directly from a
 * controller. The only write path is {@see applyMovement()}, called by
 * StockMovementService inside the same transaction as the ledger entry it
 * belongs to. See app_plan.md §88.
 */
final class InventoryService
{
    public function __construct(private Inventory $model) {}

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
            ->with(['warehouse:id,name', 'sku:id,sku,product_id,product_variant_id', 'sku.product:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sku_id', $skuId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($q) => $q->where('sku', 'like', "%{$search}%")))
            ->when(
                in_array($filters['sort'] ?? null, ['on_hand_qty', 'available_qty', 'average_cost', 'updated_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest('updated_at'),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * Apply a stock movement to a warehouse/SKU balance: upsert the row,
     * adjust on-hand quantity, recompute available quantity, and roll the
     * weighted-average cost forward on inbound movements that carry a unit
     * cost. Returns the standard envelope; rejects an outbound movement that
     * would take on-hand negative.
     *
     * @return array<string, mixed>
     */
    public function applyMovement(
        int $warehouseId,
        int $skuId,
        int $quantity,
        bool $isInbound,
        ?float $unitCost,
    ): array {
        try {
            $inventory = $this->model->newQuery()
                ->where('warehouse_id', $warehouseId)
                ->where('sku_id', $skuId)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                $inventory = $this->model->create([
                    'warehouse_id' => $warehouseId,
                    'sku_id' => $skuId,
                    'on_hand_qty' => 0,
                    'reserved_qty' => 0,
                    'available_qty' => 0,
                    'incoming_qty' => 0,
                    'average_cost' => 0,
                ]);
            }

            if (! $isInbound && $inventory->on_hand_qty < $quantity) {
                return [
                    'success' => false,
                    'message' => "Not enough stock on hand: {$inventory->on_hand_qty} available, {$quantity} requested",
                ];
            }

            if ($isInbound && $unitCost !== null) {
                $existingValue = $inventory->on_hand_qty * (float) $inventory->average_cost;
                $incomingValue = $quantity * $unitCost;
                $newOnHand = $inventory->on_hand_qty + $quantity;

                $inventory->average_cost = $newOnHand > 0
                    ? round(($existingValue + $incomingValue) / $newOnHand, 2)
                    : 0;
            }

            $inventory->on_hand_qty += $isInbound ? $quantity : -$quantity;
            $inventory->available_qty = $inventory->on_hand_qty - $inventory->reserved_qty;
            $inventory->save();

            return ['success' => true, 'message' => 'Inventory balance updated', 'data' => $inventory->fresh()];
        } catch (Throwable $exception) {
            Log::error('Failed applying stock movement to inventory', [
                'exception' => $exception->getMessage(),
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
            ]);

            return ['success' => false, 'message' => 'Error updating inventory balance'];
        }
    }

    /**
     * Set the derived incoming_qty for a warehouse/SKU balance — called by
     * PurchaseOrderService after it recomputes the outstanding-receipt
     * aggregate from its own tables (this Service has no purchase-order
     * knowledge of its own). Upserts the row at zero if this is the SKU's
     * first purchase order in that warehouse.
     */
    public function setIncoming(int $warehouseId, int $skuId, int $incomingQty): void
    {
        try {
            $this->model->newQuery()->updateOrCreate(
                ['warehouse_id' => $warehouseId, 'sku_id' => $skuId],
                ['incoming_qty' => $incomingQty],
            );
        } catch (Throwable $exception) {
            Log::error('Failed setting incoming inventory quantity', [
                'exception' => $exception->getMessage(),
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
            ]);
        }
    }
}
