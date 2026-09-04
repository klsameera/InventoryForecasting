<?php

declare(strict_types=1);

namespace Domain\Services\StockTransferService;

use App\Models\StockTransfer;
use Illuminate\Pagination\LengthAwarePaginator;

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
}
