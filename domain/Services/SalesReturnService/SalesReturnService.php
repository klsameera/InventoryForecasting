<?php

declare(strict_types=1);

namespace Domain\Services\SalesReturnService;

use App\Enums\MovementType;
use App\Enums\ReturnCondition;
use App\Enums\SalesOrderStatus;
use App\Models\SalesReturn;
use Domain\Facades\SalesOrderFacade\SalesOrderFacade;
use Domain\Facades\StockMovementFacade\StockMovementFacade;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        DB::beginTransaction();

        try {
            $lines = $data['items'] ?? [];
            unset($data['items']);

            $salesOrder = SalesOrderFacade::get((int) $data['sales_order_id']);

            if ($salesOrder === null) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Sales order not found'];
            }

            if ($salesOrder->status !== SalesOrderStatus::Confirmed) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Only confirmed sales orders can have returns recorded against them'];
            }

            $data['warehouse_id'] = $salesOrder->warehouse_id;

            $data['return_number'] = 'SR-'.Str::upper(Str::random(10));
            $return = $this->model->create($data);
            $return->update(['return_number' => 'SR-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($lines as $line) {
                $orderItem = $salesOrder->items->firstWhere('id', $line['sales_order_item_id']);

                if ($orderItem === null) {
                    DB::rollBack();

                    return ['success' => false, 'message' => 'That line does not belong to this sales order'];
                }

                $alreadyReturned = DB::table('sales_return_items')->where('sales_order_item_id', $orderItem->id)->sum('quantity');

                if ($alreadyReturned + $line['quantity'] > $orderItem->quantity) {
                    DB::rollBack();

                    return ['success' => false, 'message' => "Cannot return more than the {$orderItem->quantity} units sold on that line"];
                }

                $condition = $line['condition'] instanceof ReturnCondition ? $line['condition'] : ReturnCondition::from($line['condition']);

                $return->items()->create([
                    'sales_order_item_id' => $orderItem->id,
                    'sku_id' => $orderItem->sku_id,
                    'quantity' => $line['quantity'],
                    'condition' => $condition,
                ]);

                $returnResult = StockMovementFacade::post([
                    'warehouse_id' => $return->warehouse_id,
                    'sku_id' => $orderItem->sku_id,
                    'movement_type' => MovementType::SaleReturn,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $orderItem->cost !== null ? (float) $orderItem->cost : null,
                    'reference_type' => 'sales_return',
                    'reference_id' => $return->id,
                ]);

                if (! $returnResult['success']) {
                    DB::rollBack();

                    return $returnResult;
                }

                if ($condition === ReturnCondition::Damaged) {
                    $damageResult = StockMovementFacade::post([
                        'warehouse_id' => $return->warehouse_id,
                        'sku_id' => $orderItem->sku_id,
                        'movement_type' => MovementType::Damage,
                        'quantity' => $line['quantity'],
                        'reference_type' => 'sales_return',
                        'reference_id' => $return->id,
                    ]);

                    if (! $damageResult['success']) {
                        DB::rollBack();

                        return $damageResult;
                    }
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Sales return recorded successfully',
                'data' => $return->fresh('items'),
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            Log::error('Failed recording sales return', [
                'exception' => $exception->getMessage(),
                'data' => $data,
            ]);

            return ['success' => false, 'message' => 'Error recording sales return'];
        }
    }
}
