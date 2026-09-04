<?php

declare(strict_types=1);

namespace Domain\Services\GoodsReceiptService;

use App\Models\GoodsReceipt;
use Domain\Services\PurchaseOrderService\PurchaseOrderService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Immutable once posted — no update()/delete(). The only write path: post
 * one stock movement per line (see StockMovementService::post()), then roll
 * the receipt into the parent purchase order via
 * {@see PurchaseOrderService::registerReceipt()}.
 * See app_plan.md §20, §88.
 */
final class GoodsReceiptService
{
    public function __construct(private GoodsReceipt $model) {}

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
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'warehouse:id,name'])
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('receipt_number', 'like', "%{$search}%"))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query
                ->where('warehouse_id', $warehouseId))
            ->when($filters['purchase_order_id'] ?? null, fn ($query, $poId) => $query
                ->where('purchase_order_id', $poId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?GoodsReceipt
    {
        return $this->model
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'warehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
            ->find($id);
    }
}
