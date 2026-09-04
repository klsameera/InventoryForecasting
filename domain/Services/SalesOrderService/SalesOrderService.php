<?php

declare(strict_types=1);

namespace Domain\Services\SalesOrderService;

use App\Models\SalesOrder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Status workflow: Draft → Confirmed, or Cancelled from Draft only — a
 * confirmed order has already deducted stock (app_plan.md §16, §88), so
 * reversing one is a Sales Return, not a cancellation. Editing and deleting
 * are only allowed while Draft. {@see confirm()} is the only path that
 * touches stock: it posts a SALE movement per line, which also captures the
 * FIFO-consumed cost onto the line for margin reporting.
 */
final class SalesOrderService
{
    public function __construct(private SalesOrder $model) {}

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
            ->with('warehouse:id,name')
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($q) => $q
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query
                ->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when(
                in_array($filters['sort'] ?? null, ['order_number', 'order_date', 'total', 'status', 'created_at'], true),
                fn ($query) => $query->orderBy($filters['sort'], $filters['direction'] ?? 'asc'),
                fn ($query) => $query->latest(),
            )
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?SalesOrder
    {
        return $this->model
            ->with(['warehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
            ->find($id);
    }
}
