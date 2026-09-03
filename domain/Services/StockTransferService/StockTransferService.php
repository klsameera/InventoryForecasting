<?php

declare(strict_types=1);

namespace Domain\Services\StockTransferService;

use App\Enums\MovementType;
use App\Enums\StockTransferStatus;
use App\Models\StockTransfer;
use Domain\Facades\StockMovementFacade\StockMovementFacade;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Status workflow: Draft → Approved → Dispatched → Received, or Cancelled
 * from Draft/Approved. Editing and deleting are only allowed while Draft.
 * {@see dispatch()} posts TRANSFER_OUT at the source warehouse; {@see receive()}
 * posts TRANSFER_IN at the destination, carrying forward the FIFO cost
 * captured when the stock left the source. See app_plan.md §22.
 */
final class StockTransferService
{
    public function __construct(private StockTransfer $model) {}

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
            ->with(['sourceWarehouse:id,name', 'destinationWarehouse:id,name'])
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('transfer_number', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query
                ->where('status', $status))
            ->when(
                in_array($filters['sort'] ?? null, ['transfer_number', 'transfer_date', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?StockTransfer
    {
        return $this->model
            ->with(['sourceWarehouse:id,name', 'destinationWarehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
            ->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            if ((int) $data['source_warehouse_id'] === (int) $data['destination_warehouse_id']) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Source and destination warehouses must be different'];
            }

            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['status'] = StockTransferStatus::Draft;

            $data['transfer_number'] = 'TRF-'.Str::upper(Str::random(10));
            $transfer = $this->model->create($data);
            $transfer->update(['transfer_number' => 'TRF-'.str_pad((string) $transfer->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as $item) {
                $transfer->items()->create(['sku_id' => $item['sku_id'], 'quantity' => $item['quantity']]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Stock transfer created successfully',
                'data' => $transfer->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed creating stock transfer', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error creating stock transfer'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data, int $id): array
    {
        DB::beginTransaction();

        try {
            $transfer = $this->model->findOrFail($id);

            if ($transfer->status !== StockTransferStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft stock transfers can be edited'];
            }

            $sourceWarehouseId = $data['source_warehouse_id'] ?? $transfer->source_warehouse_id;
            $destinationWarehouseId = $data['destination_warehouse_id'] ?? $transfer->destination_warehouse_id;

            if ((int) $sourceWarehouseId === (int) $destinationWarehouseId) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Source and destination warehouses must be different'];
            }

            $items = $data['items'] ?? [];
            unset($data['items'], $data['status']);

            $transfer->update($data);
            $transfer->items()->delete();

            foreach ($items as $item) {
                $transfer->items()->create(['sku_id' => $item['sku_id'], 'quantity' => $item['quantity']]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Stock transfer updated successfully',
                'data' => $transfer->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed updating stock transfer', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error updating stock transfer'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $id): array
    {
        return $this->transition($id, StockTransferStatus::Draft, StockTransferStatus::Approved, 'approved');
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatch(int $id): array
    {
        DB::beginTransaction();

        try {
            $transfer = $this->model->with('items')->findOrFail($id);

            if ($transfer->status !== StockTransferStatus::Approved) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only approved stock transfers can be dispatched'];
            }

            foreach ($transfer->items as $item) {
                $result = StockMovementFacade::post([
                    'warehouse_id' => $transfer->source_warehouse_id,
                    'sku_id' => $item->sku_id,
                    'movement_type' => MovementType::TransferOut,
                    'quantity' => $item->quantity,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->id,
                ]);

                if (! $result['success']) {
                    DB::rollBack();

                    return $result;
                }
            }

            $transfer->update(['status' => StockTransferStatus::Dispatched]);

            DB::commit();

            return ['success' => true, 'message' => 'Stock transfer dispatched', 'data' => $transfer->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed dispatching stock transfer', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error dispatching stock transfer'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function receive(int $id): array
    {
        DB::beginTransaction();

        try {
            $transfer = $this->model->with('items')->findOrFail($id);

            if ($transfer->status !== StockTransferStatus::Dispatched) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only dispatched stock transfers can be received'];
            }

            foreach ($transfer->items as $item) {
                $carriedCost = DB::table('stock_movements')
                    ->where('reference_type', 'stock_transfer')
                    ->where('reference_id', $transfer->id)
                    ->where('sku_id', $item->sku_id)
                    ->where('movement_type', MovementType::TransferOut->value)
                    ->value('unit_cost');

                $result = StockMovementFacade::post([
                    'warehouse_id' => $transfer->destination_warehouse_id,
                    'sku_id' => $item->sku_id,
                    'movement_type' => MovementType::TransferIn,
                    'quantity' => $item->quantity,
                    'unit_cost' => $carriedCost !== null ? (float) $carriedCost : null,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->id,
                ]);

                if (! $result['success']) {
                    DB::rollBack();

                    return $result;
                }
            }

            $transfer->update(['status' => StockTransferStatus::Received]);

            DB::commit();

            return ['success' => true, 'message' => 'Stock transfer received', 'data' => $transfer->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed receiving stock transfer', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error receiving stock transfer'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $id): array
    {
        DB::beginTransaction();

        try {
            $transfer = $this->model->findOrFail($id);

            if (! in_array($transfer->status, [StockTransferStatus::Draft, StockTransferStatus::Approved], true)) {
                DB::rollBack();

                return ['success' => false, 'message' => 'This stock transfer can no longer be cancelled'];
            }

            $transfer->update(['status' => StockTransferStatus::Cancelled]);

            DB::commit();

            return ['success' => true, 'message' => 'Stock transfer cancelled', 'data' => $transfer->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed cancelling stock transfer', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error cancelling stock transfer'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transition(int $id, StockTransferStatus $from, StockTransferStatus $to, string $verb): array
    {
        DB::beginTransaction();

        try {
            $transfer = $this->model->findOrFail($id);

            if ($transfer->status !== $from) {
                DB::rollBack();

                return ['success' => false, 'message' => "Only {$from->label()} stock transfers can be {$verb}"];
            }

            $transfer->update(['status' => $to]);

            DB::commit();

            return ['success' => true, 'message' => "Stock transfer {$verb}", 'data' => $transfer->fresh()];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error("Failed marking stock transfer as {$verb}", [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => "Error marking stock transfer as {$verb}"];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        DB::beginTransaction();

        try {
            $transfer = $this->model->findOrFail($id);

            if ($transfer->status !== StockTransferStatus::Draft) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only draft stock transfers can be deleted'];
            }

            $transfer->items()->delete();
            $transfer->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Stock transfer deleted successfully'];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed deleting stock transfer', [
                'exception' => $exception->getMessage(),
                'id' => $id,
            ]);

            return ['success' => false, 'message' => 'Error deleting stock transfer'];
        }
    }
}
