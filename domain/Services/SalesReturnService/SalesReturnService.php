<?php

declare(strict_types=1);

namespace Domain\Services\SalesReturnService;

use App\Enums\SalesOrderStatus;
use App\Models\SalesReturn;
use Domain\Facades\SalesOrderFacade\SalesOrderFacade;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Immutable, posted-on-create — no update()/delete(), matching the ledger
 * pattern in StockMovementService's docblock. A sellable-condition line posts
 * an inbound SALE_RETURN, putting stock back at the SKU's original sale cost.
 * A damaged-condition line posts that same inbound SALE_RETURN followed
 * immediately by an outbound DAMAGE movement — net zero on-hand change, but
 * both events are on the ledger, matching app_plan.md §17's "separately track
 * abnormal return rates" rather than silently dropping the stock.
 */
final class SalesReturnService
{
    public function __construct(private SalesReturn $model) {}

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
            ->with(['salesOrder:id,order_number', 'warehouse:id,name'])
            ->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where('return_number', 'like', "%{$search}%"))
            ->when($filters['sales_order_id'] ?? null, fn ($query, $orderId) => $query
                ->where('sales_order_id', $orderId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    public function get(int $id): ?SalesReturn
    {
        return $this->model
            ->with(['salesOrder:id,order_number', 'warehouse:id,name', 'items.sku:id,sku,product_id', 'items.sku.product:id,name'])
            ->find($id);
    }

    /**
     * Confirmed sales orders — the only ones a return can be recorded
     * against — for the create form's picker.
     *
     * @return array<int, array{id: int, order_number: string}>
     */
    public function returnableSalesOrderOptions(): array
    {
        return DB::table('sales_orders')
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->orderByDesc('order_date')
            ->get(['id', 'order_number'])
            ->map(fn ($row) => ['id' => $row->id, 'order_number' => $row->order_number])
            ->all();
    }

    /**
     * A confirmed sales order's lines annotated with how much of each has
     * already been returned, so the create form can cap the quantity input.
     *
     * @return array<int, array{id: int, sku_id: int, sku: string, product_name: string, quantity: int, returned_qty: int, remaining_qty: int}>
     */
    public function outstandingItems(int $salesOrderId): array
    {
        $salesOrder = SalesOrderFacade::get($salesOrderId);

        if ($salesOrder === null || $salesOrder->status !== SalesOrderStatus::Confirmed) {
            return [];
        }

        $alreadyReturned = DB::table('sales_return_items')
            ->whereIn('sales_order_item_id', $salesOrder->items->pluck('id'))
            ->selectRaw('sales_order_item_id, SUM(quantity) as returned_qty')
            ->groupBy('sales_order_item_id')
            ->pluck('returned_qty', 'sales_order_item_id');

        return $salesOrder->items
            ->map(function ($item) use ($alreadyReturned) {
                $returned = (int) ($alreadyReturned[$item->id] ?? 0);

                return [
                    'id' => $item->id,
                    'sku_id' => $item->sku_id,
                    'sku' => $item->sku?->sku ?? '',
                    'product_name' => $item->sku?->product?->name ?? '',
                    'quantity' => $item->quantity,
                    'returned_qty' => $returned,
                    'remaining_qty' => $item->quantity - $returned,
                ];
            })
            ->filter(fn ($item) => $item['remaining_qty'] > 0)
            ->values()
            ->all();
    }
}
