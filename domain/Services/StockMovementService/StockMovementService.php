<?php

declare(strict_types=1);

namespace Domain\Services\StockMovementService;

use App\Enums\MovementType;
use App\Models\StockMovement;
use Domain\Facades\InventoryBatchFacade\InventoryBatchFacade;
use Domain\Facades\InventoryFacade\InventoryFacade;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Append-only ledger. There is no update() or delete() — see app_plan.md §14
 * and the module's controller docblock for why.
 */
final class StockMovementService
{
    public function __construct(private StockMovement $model) {}

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
            ->with(['warehouse:id,name', 'sku:id,sku,product_id', 'sku.product:id,name', 'user:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['sku_id'] ?? null, fn ($query, $skuId) => $query
                ->where('sku_id', $skuId))
            ->when($filters['movement_type'] ?? null, fn ($query, $type) => $query
                ->where('movement_type', $type))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereHas('sku', fn ($q) => $q->where('sku', 'like', "%{$search}%")))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * The shared write path for every module that moves stock (Purchasing,
     * Sales, Transfers, Returns, and — via store() — manual adjustments):
     * record the ledger entry, roll it into the SKU's warehouse balance, and
     * open/consume the matching FIFO batch. Does **not** open its own
     * transaction — the caller (its own Service, already mid-transaction) is
     * responsible for that, matching InventoryService::applyMovement()'s
     * convention. Any movement type is accepted here; the manual-entry
     * restriction lives in store(), not here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(array $data): array
    {
        $movementType = $data['movement_type'] instanceof MovementType
            ? $data['movement_type']
            : MovementType::from($data['movement_type']);

        $data['movement_type'] = $movementType;
        $data['occurred_at'] ??= now();

        $movement = $this->model->create($data);

        $unitCost = $movement->unit_cost !== null ? (float) $movement->unit_cost : null;
        $isInbound = $movementType->isInbound();

        $balanceResult = InventoryFacade::applyMovement(
            warehouseId: $movement->warehouse_id,
            skuId: $movement->sku_id,
            quantity: $movement->quantity,
            isInbound: $isInbound,
            unitCost: $unitCost,
        );

        if (! $balanceResult['success']) {
            return $balanceResult;
        }

        $batchCost = null;

        if ($isInbound && $unitCost !== null) {
            InventoryBatchFacade::receive(
                warehouseId: $movement->warehouse_id,
                skuId: $movement->sku_id,
                sourceType: $movement->reference_type ?? $movementType->value,
                sourceId: $movement->reference_id ?? $movement->id,
                quantity: $movement->quantity,
                unitCost: $unitCost,
                receivedDate: $movement->occurred_at,
            );
        } elseif (! $isInbound) {
            $consumed = InventoryBatchFacade::consumeFifo(
                warehouseId: $movement->warehouse_id,
                skuId: $movement->sku_id,
                quantity: $movement->quantity,
            );
            $batchCost = $consumed['weighted_cost'];

            if ($unitCost === null && $batchCost !== null) {
                $movement->update(['unit_cost' => $batchCost]);
            }
        }

        return [
            'success' => true,
            'message' => 'Stock movement recorded successfully',
            'data' => $movement->fresh(),
            'batch_cost' => $batchCost,
        ];
    }
}
